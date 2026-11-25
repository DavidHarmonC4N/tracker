#!/usr/bin/env python3
"""Builds includes/groups.json from the research spreadsheet (Directory sheet).

Usage: python3 tests/tools/import-groups.py <spreadsheet.xlsx> [includes/groups.json]

A group that serves several states is one entry with every state listed. The importer keeps only what the directory page shows.
It needs openpyxl. Re-run it whenever the spreadsheet is refreshed, then run the tests.
"""
import json
import re
import sys

import openpyxl

src = sys.argv[1]
out = sys.argv[2] if len(sys.argv) > 2 else 'includes/groups.json'
wb = openpyxl.load_workbook(src)
sheet = wb['Directory']
header = [c.value for c in sheet[5]]
PROTOCOL = {'Meshtastic': 'meshtastic', 'MeshCore': 'meshcore', 'Both': 'both'}
REVIEW = {'Website reviewed': 'reviewed', 'Directory listing': 'directory', 'Website unconfirmed': 'unconfirmed'}


def text(value, limit):
    value = re.sub(r'[\x00-\x1f\x7f]+', ' ', str(value or '')).strip()
    return value[:limit]


groups = {}
order = []
for row in sheet.iter_rows(min_row=6, max_row=sheet.max_row):
    rec = {}
    for name, cell in zip(header, row):
        rec[name] = cell.value
        if cell.hyperlink and cell.hyperlink.target:
            rec[name + '#url'] = cell.hyperlink.target
    if not rec.get('Listing ID'):
        continue
    gid = str(rec['Listing ID'])
    if gid not in groups:
        discord = rec.get('Discord#url') or ''
        members_only = 'discord.com/channels' in discord or 'Members-only' in str(rec.get('Discord access') or '')
        groups[gid] = {
            'id': gid,
            'name': text(rec['Group name'], 80),
            'city': text(rec.get('City / metro'), 60),
            'protocol': PROTOCOL[rec['Protocol']],
            'type': text(rec.get('Listing type'), 40),
            'url': rec.get('Main link#url') or '',
            'discord': discord,
            'discord_members_only': bool(discord and members_only),
            'facebook': rec.get('Facebook#url') or '',
            'other': rec.get('Other resource#url') or '',
            'map': rec.get('Map / traffic#url') or '',
            'coverage': text(rec.get('Coverage area'), 110),
            'notes': text(rec.get('Group notes'), 160),
            'review': REVIEW.get(rec.get('Website review'), 'directory'),
            'states': [],
        }
        order.append(gid)
    state = text(rec['State or region'], 40)
    if state not in groups[gid]['states']:
        groups[gid]['states'].append(state)

data = {'verified': '2026-11-25', 'groups': [groups[g] for g in order]}
with open(out, 'w') as handle:
    json.dump(data, handle, indent=1, ensure_ascii=False)
    handle.write('\n')
print(len(order), 'groups,', sum(len(g['states']) for g in data['groups']), 'state listings ->', out)
