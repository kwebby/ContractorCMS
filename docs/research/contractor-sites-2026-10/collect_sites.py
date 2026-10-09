#!/usr/bin/env python3
# Author: ramanpal singh
# URL: https://kwebby.com
"""Read-only official-site evidence capture. No forms, logins, or media downloads.

Requires requests and beautifulsoup4. Run with PYTHONPATH pointing to a temporary
installation if these are unavailable. Sitemap counts are advertised URL counts,
not a claim that every URL is reachable or indexed by a search engine.
"""
import concurrent.futures
import datetime
import hashlib
import json
import pathlib
import re
import tempfile
import time
import urllib.parse
import xml.etree.ElementTree as ET

import requests
from bs4 import BeautifulSoup

ROOT = pathlib.Path(__file__).resolve().parent
SITES = [
    ('bone-dry', 'Bone Dry Roofing', 'Roofing', 'Indianapolis, IN', 'US', 'https://bonedry.com/indianapolis/'),
    ('baker', 'Baker Roofing Company', 'Roofing', 'Cary / Raleigh, NC', 'US', 'https://bakerroofing.com/'),
    ('cherry-clark', 'Cherry & Clark Roofing', 'Roofing', 'Mississauga, ON', 'Canada', 'https://www.cherryandclarkroofing.ca/'),
    ('penfolds', 'Penfolds Roofing & Solar', 'Roofing', 'Vancouver, BC', 'Canada', 'https://www.penfoldsroofing.com/'),
    ('len', 'Len The Plumber Heating & Air', 'Plumbing', 'Baltimore, MD', 'US', 'https://lentheplumber.com/'),
    ('reliant', 'Reliant Plumbing', 'Plumbing', 'Austin, TX', 'US', 'https://reliantplumbing.com/'),
    ('john', 'John The Plumber', 'Plumbing', 'Ottawa, ON', 'Canada', 'https://johntheplumber.ca/'),
    ('milani', 'Milani Plumbing, Heating & Air Conditioning', 'Plumbing', 'Burnaby, BC', 'Canada', 'https://www.milani.ca/'),
    ('west-houston', 'West Houston Electric', 'Electrical', 'Katy / Houston, TX', 'US', 'https://www.westhoustonelectric.com/'),
    ('thomas-edison', 'Thomas Edison Electric', 'Electrical', 'Newtown / Philadelphia, PA', 'US', 'https://thomasedisonelectric.com/'),
    ('expert', 'Expert Electric', 'Electrical', 'Vancouver / Lower Mainland, BC', 'Canada', 'https://expertelectric.ca/'),
    ('mister-sparky', 'Mister Sparky of Houston', 'Electrical', 'Houston, TX', 'US', 'https://www.mistersparky.com/houston/'),
    ('morris-jenkins', 'Morris-Jenkins', 'HVAC', 'Charlotte, NC', 'US', 'https://morrisjenkins.com/'),
    ('parker', 'Parker & Sons', 'HVAC', 'Phoenix, AZ', 'US', 'https://www.parkerandsons.com/'),
    ('ashton', 'Ashton Plumbing Heating and Air Conditioning', 'HVAC', 'Richmond / Vancouver, BC', 'Canada', 'https://www.ashtonservicegroup.com/'),
    ('hiller', 'Hiller Plumbing, Heating, Cooling & Electrical', 'HVAC', 'Nashville, TN', 'US', 'https://happyhiller.com/'),
    ('neil-kelly', 'Neil Kelly', 'Remodeling', 'Portland, OR', 'US', 'https://www.neilkelly.com/'),
    ('case', 'Case Architects & Remodelers', 'Remodeling', 'Bethesda, MD / Washington, DC', 'US', 'https://www.casedesign.com/'),
    ('sosna', 'SOSNA', 'Remodeling', 'Vaughan / Toronto, ON', 'Canada', 'https://sosna.ca/'),
    ('jackson', 'Jackson Design & Remodeling', 'Remodeling', 'San Diego, CA', 'US', 'https://www.jacksondesignandremodeling.com/'),
    ('bowa', 'BOWA', 'General / design-build', 'McLean, VA', 'US', 'https://www.bowa.com/'),
    ('lee-lewis', 'Lee Lewis Construction', 'General / commercial', 'Lubbock, TX', 'US', 'https://www.leelewis.com/'),
    ('chandos', 'Chandos Construction', 'General / commercial', 'Edmonton, AB', 'Canada', 'https://www.chandos.com/'),
    ('alair', 'Alair Vancouver', 'General / custom homes', 'Vancouver, BC', 'Canada', 'https://alairhomes.com/vancouver/'),
]

def fetch(url):
    try:
        r = requests.get(url, timeout=22, headers={'User-Agent': 'Mozilla/5.0 (compatible; ContractorCMSResearch/1.0)'})
        return {'url': url, 'final_url': r.url, 'status': r.status_code, 'type': r.headers.get('content-type', ''), 'body': r.text}
    except Exception as e:
        return {'url': url, 'status': None, 'error': str(e), 'body': ''}

def normalize(url):
    p = urllib.parse.urlsplit(url)
    return urllib.parse.urlunsplit((p.scheme, p.netloc.lower().removeprefix('www.'), p.path or '/', '', ''))

def is_page(url, host):
    p = urllib.parse.urlsplit(url)
    return p.scheme in ('http', 'https') and p.netloc.lower().removeprefix('www.') == host.removeprefix('www.') and not re.search(r'\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|mp4|xml|css|js|ico)$', p.path, re.I) and not re.search(r'/(wp-admin|wp-json|feed|uploads)/', p.path)

def collect(site):
    started = time.monotonic()
    ident, name, trade, city, country, url = site
    result = dict(id=ident, name=name, trade=trade, city=city, country=country, homepage=url, accessed_at_utc=datetime.datetime.now(datetime.timezone.utc).isoformat())
    home = fetch(url)
    result['http'] = {k: v for k,v in home.items() if k != 'body'}
    result['html_sha256'] = hashlib.sha256(home['body'].encode()).hexdigest()
    soup = BeautifulSoup(home['body'], 'html.parser')
    result['headings'] = [{'tag': h.name, 'text': h.get_text(' ', strip=True)} for h in soup.select('h1,h2,h3,h4')]
    result['images'] = [{'alt': im.get('alt', ''), 'src': im.get('src', im.get('data-src', ''))} for im in soup.select('img')][:130]
    result['background_images'] = re.findall(r'url\([\'\"]?([^\)\'\"]+)', home['body'])[:30]
    result['videos'] = [v.get('src', '') for v in soup.select('video,video source')]
    result['meta'] = {el.get('name', el.get('property')): el.get('content') for el in soup.select('meta[name],meta[property]')}
    result['canonical'] = [el.get('href') for el in soup.select('link[rel=canonical]')]
    result['schema'] = [el.get_text() for el in soup.select('script[type="application/ld+json"]')]
    result['links'] = [{'text': a.get_text(' ', strip=True), 'url': urllib.parse.urljoin(home.get('final_url', url), a.get('href', ''))} for a in soup.select('a[href]')]
    result['phones'] = sorted(set(a['url'] for a in result['links'] if a['url'].startswith('tel:')))
    for el in soup.select('script,style,noscript'):
        el.decompose()
    result['text'] = soup.get_text('\n', strip=True)
    host = urllib.parse.urlsplit(home.get('final_url', url)).netloc.lower()
    origin = urllib.parse.urlunsplit(('https', host, '/', '', ''))
    home_urls = set(normalize(a['url']) for a in result['links'] if is_page(a['url'], host))
    home_urls.add(normalize(home.get('final_url', url)))
    result['homepage_unique_page_links'] = sorted(home_urls)
    # Branch exemplars use a bounded local path inventory; do not count network
    # sitemaps as pages belonging to the sampled local business.
    scope_prefix = {'bone-dry':'/indianapolis/', 'mister-sparky':'/houston/', 'alair':'/vancouver/'}.get(ident)
    result['count_scope'] = scope_prefix or 'same hostname (www normalized)'
    candidates = [urllib.parse.urljoin(origin, 'robots.txt'), urllib.parse.urljoin(origin, 'sitemap.xml'), urllib.parse.urljoin(origin, 'sitemap_index.xml'), urllib.parse.urljoin(origin, 'wp-sitemap.xml')]
    robots = fetch(candidates.pop(0))
    candidates = re.findall(r'^Sitemap:\s*(\S+)', robots['body'], re.I|re.M) + candidates
    result['robots_source'] = {k:v for k,v in robots.items() if k!='body'}
    visited, sitemap_urls, maps, queue = set(), set(), [], list(dict.fromkeys(candidates))
    referenced_children = set()
    capped = False
    while queue:
        if len(visited) >= 35 or time.monotonic() - started > 120:
            capped = True
            break
        sm = queue.pop(0)
        if sm in visited:
            continue
        visited.add(sm)
        resp = fetch(sm)
        meta = {k:v for k,v in resp.items() if k!='body'}
        try:
            tree = ET.fromstring(resp['body'])
            kind = tree.tag.split('}')[-1]
            # Only direct sitemap/url locations; nested media locations do not
            # represent document pages and must never inflate the page count.
            parent_tag = 'sitemap' if kind == 'sitemapindex' else 'url'
            locs = [el.text.strip() for parent in tree if parent.tag.split('}')[-1] == parent_tag for el in parent if el.tag.split('}')[-1] == 'loc' and el.text]
            meta.update(kind=kind, loc_count=len(locs))
            if kind=='sitemapindex':
                referenced_children.update(locs)
                queue.extend(l for l in locs if l not in visited)
            elif kind=='urlset':
                sitemap_urls.update(normalize(l) for l in locs if is_page(l,host))
        except Exception:
            meta['parse_result']='not XML sitemap'
        maps.append(meta)
    result['sitemaps'] = maps
    result['sitemap_capped'] = capped
    accepted = {m['url'] for m in maps if m.get('kind') in ('sitemapindex','urlset')}
    result['failed_sitemap_children'] = sorted(referenced_children - accepted)
    result['sitemap_inventory_partial'] = capped or bool(result['failed_sitemap_children'])
    result['sitemap_advertised_page_urls'] = sorted(sitemap_urls)
    scope_urls = {u for u in sitemap_urls if not scope_prefix or urllib.parse.urlsplit(u).path.startswith(scope_prefix)}
    scope_home = {u for u in home_urls if not scope_prefix or urllib.parse.urlsplit(u).path.startswith(scope_prefix)}
    if scope_urls:
        result['page_count'] = len(scope_urls)
        result['page_count_method'] = 'Unique HTML-candidate URLs advertised in fetched XML sitemaps, scoped as above; no per-URL reachability or indexing validation' + ('; request/time cap reached, partial inventory' if capped else '; all accepted sitemap children within request/time cap processed; sitemaps may omit public pages')
        result['count_urls'] = sorted(scope_urls)
    else:
        result['page_count'] = len(scope_home)
        result['page_count_method'] = 'Lower bound: unique same-host HTML-candidate URLs linked from sampled homepage, including homepage; local path scope where specified. No complete sitemap inventory available for scope; not total site size.'
        result['count_urls'] = sorted(scope_home)
    # Raw text is temporary inspection material, excluded from deliverables.
    temp_root = pathlib.Path(tempfile.gettempdir()) / 'contractor-cms-research-2026-10'
    temp_root.mkdir(exist_ok=True)
    (temp_root/f'{ident}.json').write_text(json.dumps(result, indent=2, ensure_ascii=False))
    result = publishable_evidence(result)
    (ROOT/'evidence').mkdir(exist_ok=True)
    (ROOT/'evidence'/f'{ident}.json').write_text(json.dumps(result, indent=2, ensure_ascii=False))
    return {'id':ident,'status':home['status'],'page_count':result['page_count'],'phones':result['phones']}

def publishable_evidence(result):
    """Keep inventories and factual metadata, excluding copied page prose."""
    fields = ['id','name','trade','city','country','homepage','accessed_at_utc','http',
              'html_sha256','canonical','phones','count_scope','robots_source',
              'homepage_unique_page_links','sitemaps','sitemap_capped',
              'failed_sitemap_children','sitemap_inventory_partial',
              'sitemap_advertised_page_urls','page_count','page_count_method','count_urls']
    clean = {k: result[k] for k in fields if k in result}
    clean['html_heading_count'] = len(result.get('headings', []))
    clean['html_image_count'] = len(result.get('images', []))
    clean['phone_link_pairs'] = [{'display':a['text'], 'href':a['url']} for a in result.get('links',[]) if a['url'].startswith('tel:') and re.fullmatch(r'[\d\s().+\-]+',a['text'])]
    types = set()
    def walk(obj):
        if isinstance(obj,dict):
            t=obj.get('@type',[])
            types.update(t if isinstance(t,list) else [t])
            for v in obj.values():walk(v)
        elif isinstance(obj,list):
            for v in obj:walk(v)
    for raw in result.get('schema',[]):
        try:walk(json.loads(raw))
        except Exception:pass
    clean['jsonld_types'] = sorted(types)
    return clean

if __name__ == '__main__':
    import sys
    selected = [s for s in SITES if len(sys.argv)==1 or s[0] in sys.argv[1:]]
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
        for row in pool.map(collect, selected):
            print(json.dumps(row, ensure_ascii=False), flush=True)
