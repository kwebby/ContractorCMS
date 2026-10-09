#!/usr/bin/env python3
# Author: ramanpal singh
# URL: https://kwebby.com
"""Render reviewed observations and factual URL inventories with the stdlib."""
import collections
import csv
import json
import pathlib
import tempfile
import urllib.parse

ROOT = pathlib.Path(__file__).resolve().parent

def norm(url):
    p = urllib.parse.urlsplit(url)
    return urllib.parse.urlunsplit((p.scheme,p.netloc.lower().removeprefix('www.'),p.path or '/', '', ''))

def csv_write(path, rows, fields):
    with path.open('w', newline='', encoding='utf-8') as output:
        writer=csv.DictWriter(output,fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)

def main():
    observations=json.loads((ROOT/'observations.json').read_text())
    temp_root=pathlib.Path(tempfile.gettempdir())/'contractor-cms-research-2026-10'
    temp_root.mkdir(exist_ok=True)
    # Migrate legacy inspection captures out of the deliverable directory.
    for raw_text in (ROOT/'evidence').glob('*.txt'):
        raw_text.replace(temp_root/raw_text.name)
    rows=[]
    notes=['<!-- Author: ramanpal singh | URL: https://kwebby.com -->', '', '# Official contractor website observations — 9 October 2026', '',
        '24 real businesses: 15 US and 9 Canadian; four examples in each of roofing, plumbing, electrical, HVAC, remodeling, and general/design-build. Trades overlap; these are sampling groups, not exclusive licenses. Two large commercial general contractors provide a deliberate contrast to local residential businesses. This is a purposive sample, not a market census.', '',
        'Body order is a conservative reading of retrieved HTML/web text, with repeated responsive copies and navigation/footer content excluded. It is not a pixel-level visual audit. Image patterns come from embedded asset metadata and official image descriptions; no remote images were downloaded. Four retrieved pages did not expose a verifiable hero. A section omitted from the dataset means not observed in the available extract, not proof that it never renders.', '',
        'All observations are paraphrased. No customer testimonials or full scraped page prose/HTML are distributed. NAP means business name, address and phone. This research checks sampled on-site representations only. **Google Business Profile and other independent listing consistency was not verified for any of the 24 businesses.** A map/review link on a site does not establish cross-platform consistency.', '',
        'Counts below use **S** = unique page-candidate URLs advertised by fetched XML sitemaps; **M** = manually deduplicated bounded subset of official homepage links, a lower bound. Neither is an exact total of live/indexed pages. Full rules and limits are in [PAGE-COUNT-METHOD.md](PAGE-COUNT-METHOD.md).', '',
        '| Business | Country | Group | Discoverable URLs | Other URLs | Method | Scope |',
        '|---|---|---|---:|---:|---|---|']
    evidence_fields=['id','name','trade','city','country','homepage','accessed_at_utc','http','html_sha256','canonical','phones','count_scope','robots_source','homepage_unique_page_links','sitemaps','sitemap_capped','failed_sitemap_children','sitemap_inventory_partial','sitemap_advertised_page_urls','page_count','page_count_method','count_urls','html_heading_count','html_image_count','phone_link_pairs','jsonld_types']
    for observation in observations:
        ident=observation['id']
        evidence_path=ROOT/'evidence'/f'{ident}.json'
        original=json.loads(evidence_path.read_text())
        if 'text' in original:
            (temp_root/f'{ident}.json').write_text(json.dumps(original,ensure_ascii=False,indent=2))
        ev={k:original[k] for k in evidence_fields if k in original}
        if observation.get('manual_count_urls'):
            ev['count_urls']=sorted(set(norm(u) for u in observation['manual_count_urls']))
            ev['page_count']=len(ev['count_urls'])
            ev['page_count_method']='M: manually deduplicated bounded subset of links in official homepage web extraction, including homepage. Lower bound, not complete navigation inventory or site total; not all linked targets confirmed reachable.'
            ev['sitemap_inventory_partial']=True
            ev['failed_sitemap_children']=[]
            ev['manual_inventory_checked_date']='2026-10-09'
            ev['web_fallback_used']=True
            ev['web_fallback_sources']=observation['sources']
            if ident=='len':
                ev['linked_target_access_limit']='Official /drain-cleaning/ link was discoverable but web fetch timed out.'
            if ident=='jackson':
                ev['linked_target_access_limit']='Homepage contact link resolves as /contact-%20us/ in web extraction; /contact-us/ also separately opened.'
        else:
            ev['web_fallback_used']=False
        if ident=='baker':
            ev['city']='Cary / Raleigh, NC'
        ev['nap_cross_platform_status']='not_verified'
        home_identities={norm(ev['homepage'])}
        if ev['http'].get('status')==200 and ev['http'].get('final_url'):
            home_identities.add(norm(ev['http']['final_url']))
        ev['non_home_page_count']=sum(norm(u) not in home_identities for u in ev['count_urls'])
        ev['non_home_page_count_method']='Counted inventory excluding normalized sampled homepage and confirmed successful final homepage redirect URL; do not subtract 1 when sitemap omits the homepage.'
        ev['non_home_excluded_identities']=sorted(home_identities)
        evidence_path.write_text(json.dumps(ev,ensure_ascii=False,indent=2)+'\n')
        kind='M' if observation.get('manual_count_urls') else 'S'
        group=ev['trade'].split(' /')[0]
        scope=ev['count_scope']
        if ident in ['bone-dry','alair']:
            scope='sampled local branch path only'
        elif ident=='mister-sparky':
            scope='/houston/ advertised paths only; network excluded'
        else:
            scope='company hostname across all offices/regions'
        sitemap_sources=sorted(set(m.get('final_url',m['url']) for m in ev['sitemaps'] if m.get('kind') in ['sitemapindex','urlset']))
        rows.append(dict(id=ident,business=ev['name'],trade_group=group,trade_detail=ev['trade'],city=ev['city'],country=ev['country'],homepage=ev['homepage'],checked_date='2026-10-09',body_sections_order=';'.join(observation['body_sections_order']),cta_actions=';'.join(observation['ctas']),imagery_pattern=observation['imagery'],nap_business_name=ev['name'],nap_address=observation['nap_address'],nap_phone=observation['nap_phone'],page_types=';'.join(observation['page_types']),nap_risk_flags=';'.join(observation['risk_flags']),nap_cross_platform_status='not_verified',discoverable_page_candidates=ev['page_count'],count_kind=kind,count_scope=scope,count_method=ev['page_count_method'],sitemap_inventory_partial=ev.get('sitemap_inventory_partial',False),sitemap_request_cap_reached=ev.get('sitemap_capped',False),http_status=ev['http'].get('status') or 'timeout/error',web_fallback_used=ev['web_fallback_used'],source_urls=';'.join(observation['sources']),sitemap_source_urls=';'.join(sitemap_sources),url_inventory=f'evidence/{ident}.json'))
        rows[-1]['non_home_page_count']=ev['non_home_page_count']
        rows[-1]['on_site_nap_scope']='sampled_pages_only_not_certified'
        notes.append(f"| [{ev['name']}]({ev['homepage']}) | {ev['country']} | {group} | {'≥' if kind=='M' else ''}{ev['page_count']:,} | {'≥' if kind=='M' else ''}{ev['non_home_page_count']:,} | {kind} | {scope} |")
    notes += ['', '## Per-company notes', '']
    for observation,row in zip(observations,rows):
        notes += [f"### {row['business']} — {row['city']}, {row['country']}", '',
                  '**Sources:** '+', '.join(f'[official {"homepage" if i==0 else "supporting page "+str(i)}]({url})' for i,url in enumerate(observation['sources']))+'.', '',
                  '- **Header:** '+observation['header']+'. **Footer:** '+observation['footer']+'.',
                  '- **Body order:** '+' → '.join(observation['body_sections_order'])+'.',
                  '- **Actions / images:** '+', '.join(observation['ctas'])+'. '+observation['imagery'],
                  '- **NAP / coverage:** '+observation['nap_address']+'. Phone: '+observation['nap_phone']+'. '+observation['branches'],
                  '- **Other page types:** '+', '.join(observation['page_types'])+'.',
                  '- **Local signals / limits:** '+observation['local_seo']+' '+observation['nap_risks']]
        if observation.get('body_order_note'):
            notes.append('- **Extraction note:** '+observation['body_order_note'])
        count_label=f"≥{row['discoverable_page_candidates']}" if row['count_kind']=='M' else str(row['discoverable_page_candidates'])
        other_label=f"≥{row['non_home_page_count']}" if row['count_kind']=='M' else str(row['non_home_page_count'])
        notes.append(f"- **Count:** {count_label} ({row['count_kind']}); {other_label} other page candidates; {row['count_scope']}. [Metadata and counted URL inventory](evidence/{row['id']}.json)." + (' Sitemap sources: '+', '.join(f'[XML {i+1}]({u})' for i,u in enumerate(row['sitemap_source_urls'].split(';'))) + '.' if row['sitemap_source_urls'] else ' XML retrieval unavailable; bounded manual subset.'))
        notes.append('')
    (ROOT/'COMPANY-SITE-NOTES.md').write_text('\n'.join(notes)+'\n')
    csv_write(ROOT/'contractor-sites.csv',rows,list(rows[0]))
    totals=collections.Counter(k for ob in observations for k in set(ob['body_sections_order']))
    groups=sorted(set(r['trade_group'] for r in rows))
    frequencies=[]
    for category,count in totals.most_common():
        item={'section_category':category,'sites_observed':count,'sample_size':24,'percent':round(count/24*100,1)}
        for group in groups:
            item[group]=sum(category in set(ob['body_sections_order']) for ob,row in zip(observations,rows) if row['trade_group']==group)
        frequencies.append(item)
    csv_write(ROOT/'section-frequencies.csv',frequencies,list(frequencies[0]))
    summary={'sample_size':24,'country_counts':dict(collections.Counter(r['country'] for r in rows)),'trade_group_counts':dict(collections.Counter(r['trade_group'] for r in rows)),'sitemap_inventories':sum(r['count_kind']=='S' for r in rows),'manual_lower_bounds':sum(r['count_kind']=='M' for r in rows),'nap_cross_platform_verified':0,'method':'Each body category counted at most once per business; header/footer, nested navigation and responsive duplicates excluded. Absence means not observed. CTA means a dedicated body action section, not every hero button. Hero visibility unverified for Bone Dry, BOWA, Jackson and Alair. HTML/web extraction, not a pixel-level audit.','frequencies':frequencies}
    (ROOT/'section-frequencies.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in summary.items() if k!='frequencies'},ensure_ascii=False,indent=2))

if __name__=='__main__':
    main()
