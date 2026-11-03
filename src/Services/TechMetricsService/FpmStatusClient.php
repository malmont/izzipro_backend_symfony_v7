<?php

namespace App\Services\TechMetricsService;

/**
 * Lecture de la page de statut de PHP-FPM (`pm.status_path = /fpm-status`, activée dans le pool mais exposée par aucune route nginx)
 * en FastCGI direct sur le port d'écoute du pool, depuis le conteneur (tableau de bord technique, T1, 03/11/2026). Client FastCGI
 * minimal : un enregistrement BEGIN_REQUEST, les paramètres, une entrée vide, puis la sortie standard jusqu'à END_REQUEST.
 * Renvoie le JSON du statut (processus actifs, inactifs, file d'attente, max atteint, requêtes lentes…) ou null si le pool ne répond pas.
 * Le plafond `pm.max_children` n'est pas dans le statut : il est lu dans les fichiers du pool (dernière définition gagnante).
 */
class FpmStatusClient
{
    private const FCGI_BEGIN_REQUEST = 1;
    private const FCGI_END_REQUEST = 3;
    private const FCGI_PARAMS = 4;
    private const FCGI_STDIN = 5;
    private const FCGI_STDOUT = 6;
    private const FCGI_RESPONDER = 1;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 9000,
        private readonly string $statusPath = '/fpm-status',
        private readonly string $poolConfigGlob = '/usr/local/etc/php-fpm.d/*.conf',
    ) {
    }

    /** @return array<string, mixed>|null statut JSON, enrichi de `max_children` quand il est lisible */
    public function status(): ?array
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 1.0);
        if (!$socket) {
            return null;
        }
        try {
            stream_set_timeout($socket, 2);
            $params = [
                'GATEWAY_INTERFACE' => 'CGI/1.1', 'REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => $this->statusPath, 'SCRIPT_FILENAME' => $this->statusPath,
                'REQUEST_URI' => $this->statusPath . '?json', 'QUERY_STRING' => 'json', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'SERVER_SOFTWARE' => 'app_v2-tech-metrics',
                'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'REMOTE_ADDR' => '127.0.0.1', 'CONTENT_LENGTH' => '0',
            ];
            $request = $this->record(self::FCGI_BEGIN_REQUEST, pack('nCx5', self::FCGI_RESPONDER, 0))
                . $this->record(self::FCGI_PARAMS, $this->encodeParams($params))
                . $this->record(self::FCGI_PARAMS, '')
                . $this->record(self::FCGI_STDIN, '');
            fwrite($socket, $request);
            $output = '';
            while (!feof($socket)) {
                $header = $this->readExactly($socket, 8);
                if ($header === null) {
                    break;
                }
                $h = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $header);
                $content = $h['length'] > 0 ? $this->readExactly($socket, $h['length']) : '';
                if ($h['padding'] > 0) {
                    $this->readExactly($socket, $h['padding']);
                }
                if ($h['type'] === self::FCGI_STDOUT) {
                    $output .= (string) $content;
                } elseif ($h['type'] === self::FCGI_END_REQUEST) {
                    break;
                }
            }
        } finally {
            fclose($socket);
        }
        $separator = strpos($output, "\r\n\r\n");
        $body = $separator === false ? $output : substr($output, $separator + 4);
        $status = json_decode(trim($body), true);
        if (!is_array($status) || !isset($status['active processes'])) {
            return null;
        }
        $max = $this->maxChildren();
        if ($max !== null) {
            $status['max_children'] = $max;
        }

        return $status;
    }

    /** `pm.max_children` du pool : dernière définition des fichiers du pool, dans l'ordre alphabétique (ordre de lecture de PHP-FPM) */
    public function maxChildren(): ?int
    {
        $files = glob($this->poolConfigGlob) ?: [];
        sort($files);
        $max = null;
        foreach ($files as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (preg_match('/^\s*pm\.max_children\s*=\s*(\d+)/', $line, $m)) {
                    $max = (int) $m[1];
                }
            }
        }

        return $max;
    }

    private function record(int $type, string $content): string
    {
        return pack('CCnnCC', 1, $type, 1, strlen($content), 0, 0) . $content;
    }

    private function encodeParams(array $params): string
    {
        $out = '';
        foreach ($params as $name => $value) {
            $out .= $this->length(strlen($name)) . $this->length(strlen((string) $value)) . $name . $value;
        }

        return $out;
    }

    private function length(int $length): string
    {
        return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
    }

    /** @param resource $socket */
    private function readExactly($socket, int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                return strlen($data) === $length ? $data : null;
            }
            $data .= $chunk;
        }

        return $data;
    }
}
