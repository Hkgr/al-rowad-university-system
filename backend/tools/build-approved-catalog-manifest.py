#!/usr/bin/env python3
"""Deterministic read-only XLSX extraction; stdout contains academic data only."""
import zipfile, xml.etree.ElementTree as ET, json, posixpath, collections, hashlib
import argparse, sys
sys.stdout.reconfigure(encoding='utf-8', newline='\n')
cli=argparse.ArgumentParser(description='Read the approved workbook; emit a derived manifest, never modify the workbook.')
cli.add_argument('workbook')
path=cli.parse_args().workbook
ns={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
book={}
with zipfile.ZipFile(path) as z:
 shared=[''.join(c.itertext()) for c in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('s:si',ns)] if 'xl/sharedStrings.xml' in z.namelist() else []
 rel={r.attrib['Id']:r.attrib['Target'] for r in ET.fromstring(z.read('xl/_rels/workbook.xml.rels'))}
 for sh in ET.fromstring(z.read('xl/workbook.xml')).find('s:sheets',ns):
  target=rel[sh.attrib['{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id']]
  target=target.lstrip('/') if target.startswith('/') else posixpath.normpath('xl/'+target)
  sheet={}
  for row in ET.fromstring(z.read(target)).findall('s:sheetData/s:row',ns):
   cells={}
   for c in row:
    if c.tag.split('}')[-1]!='c': continue
    v=c.find('s:v',ns); value=v.text if v is not None else None
    if c.attrib.get('t')=='s' and value is not None:value=shared[int(value)]
    elif c.attrib.get('t')=='inlineStr':value=''.join(c.find('s:is',ns).itertext())
    cells[''.join(t for t in c.attrib['r'] if t.isalpha())]=value
   sheet[int(row.attrib['r'])]=cells
  heads=sheet.get(5,{})
  book[sh.attrib['name']]=[{'_row':k,**{heads.get(c,c):v for c,v in row.items() if heads.get(c,c)}} for k,row in sheet.items() if k>5 and any(v is not None for v in row.values())]

formulas=[]
with zipfile.ZipFile(path) as z:
 for filename in z.namelist():
  if filename.startswith('xl/worksheets/sheet') and filename.endswith('.xml'):
   for c in ET.fromstring(z.read(filename)).findall('.//s:c',ns):
    f=c.find('s:f',ns)
    if f is not None:formulas.append({'part':filename,'cell':c.attrib['r'],'expression':f.text,'cached':c.find('s:v',ns).text if c.find('s:v',ns) is not None else None})
expected=['Start','Programs','Curriculum','Electives','Requirements','Prerequisites','Decisions','Catalog','Sources']
if list(book)!=expected:raise ValueError('Unexpected workbook sheets/order')
meta={'schema_version':1,'manifest_id':'alrowad-approved-catalog-2026-10','workbook_sha256':hashlib.sha256(open(path,'rb').read()).hexdigest(),'baseline_sql_sha256':'bb75f4674616ed872ac8f25c2b37821654d764f5961a1f2cf97a59bcb0cc8dd9'}
def cell(value):return json.dumps(value,ensure_ascii=False,separators=(',',':'))
lines=['{']+['  '+cell(k)+': '+cell(v)+',' for k,v in meta.items()]+['  '+cell('sheets')+': {']
for i,(name,rows) in enumerate(book.items()):
 lines+=['    '+cell(name)+': [']+['      '+cell(row)+(',' if j<len(rows)-1 else '') for j,row in enumerate(rows)]+['    ]'+(',' if i<len(book)-1 else '')]
lines+=['  },','  '+cell('formulas')+': [']+['    '+cell(f)+(',' if i<len(formulas)-1 else '') for i,f in enumerate(formulas)]+['  ]','}']
print('\n'.join(lines))
