#!/usr/bin/env python3
"""comparer.py <avant> <après> : compare deux photos (codes HTTP et corps). JSON : chemins ajoutés, retirés, changés (les index de liste sont notés [])."""
import json, os, sys, re, collections
a, b = sys.argv[1], sys.argv[2]
ignore = re.compile(sys.argv[3]) if len(sys.argv) > 3 else None
def flat(x, p=''):
    if isinstance(x, dict):
        for k, v in x.items(): yield from flat(v, p + '.' + str(k))
    elif isinstance(x, list):
        for i, v in enumerate(x): yield from flat(v, p + '[%d]' % i)
    else: yield p, x
gen = lambda p: re.sub(r'\[\d+\]', '[]', p)
added, removed, changed = collections.Counter(), collections.Counter(), collections.Counter()
samples = {}
files = sorted(set(os.listdir(a)) | set(os.listdir(b)))
same = diff = 0
for f in files:
    pa, pb = os.path.join(a, f), os.path.join(b, f)
    if not os.path.exists(pa) or not os.path.exists(pb):
        print('FICHIER', 'absent avant' if not os.path.exists(pa) else 'absent après', f); diff += 1; continue
    ca, cb = open(pa, 'rb').read(), open(pb, 'rb').read()
    if ca == cb: same += 1; continue
    if f.endswith('.code'):
        print('CODE', f, ca.decode().strip(), '->', cb.decode().strip()); diff += 1; continue
    try: ja, jb = json.loads(ca), json.loads(cb)
    except Exception:
        la, lb = ca.decode(errors='replace').splitlines(), cb.decode(errors='replace').splitlines()
        n = sum(1 for x, y in zip(la, lb) if x != y) + abs(len(la) - len(lb))
        print('TEXTE', f, n, 'ligne(s) différente(s) ;', next(((x[:140], y[:140]) for x, y in zip(la, lb) if x != y), '')); diff += 1; continue
    fa, fb = dict(flat(ja)), dict(flat(jb))
    d = False
    for p in fb.keys() - fa.keys():
        if ignore and ignore.search(p): continue
        added[(gen(p))] += 1; samples.setdefault(('+', gen(p)), (f, fb[p])); d = True
    for p in fa.keys() - fb.keys():
        if ignore and ignore.search(p): continue
        removed[gen(p)] += 1; samples.setdefault(('-', gen(p)), (f, fa[p])); d = True
    for p in fa.keys() & fb.keys():
        if fa[p] != fb[p]:
            if ignore and ignore.search(p): continue
            changed[gen(p)] += 1; samples.setdefault(('~', gen(p)), (f, fa[p], fb[p])); d = True
    diff += d; same += (not d)
print('fichiers identiques (hors champs écartés) : %d ; différents : %d' % (same, diff))
for title, c, s in (('AJOUTÉ', added, '+'), ('RETIRÉ', removed, '-'), ('CHANGÉ', changed, '~')):
    for p, n in sorted(c.items()): print(title, n, p, '| ex.', str(samples[(s, p)])[:170])
