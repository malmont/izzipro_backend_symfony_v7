-- Lot G-INVITES (21/11/2026, docs/ecrans-et-contextes.md § 12) : la liste des invitations demandées par le siège, base maître.
-- La SEULE source de la liste est le journal du groupe (`shop_group_events`, événement `invitation_requested`, écrit dans la requête du
-- siège, avant la file) : un code inconnu et un vrai site s'y lisent pareil. La file note le numéro de la demande (l'identifiant de cet
-- événement) sur la vraie invitation qu'elle crée, pour que l'annulation la ferme et qu'une invitation acceptée retire sa demande.
-- Idempotent ; aucune ligne existante n'est modifiée (colonne ajoutée sans valeur). Retour arrière :
--   DROP INDEX idx_shop_group_invitations_request; DROP INDEX idx_shop_group_events_requests;
--   ALTER TABLE shop_group_invitations DROP COLUMN request_id;
ALTER TABLE shop_group_invitations ADD COLUMN IF NOT EXISTS request_id BIGINT DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_shop_group_invitations_request ON shop_group_invitations (request_id) WHERE request_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_shop_group_events_requests ON shop_group_events (group_id, event, id) WHERE event IN ('invitation_requested', 'invitation_cancelled');
