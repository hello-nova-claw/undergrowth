"""Split four Project Gutenberg texts into blog-length posts as block markup.

The corpus for docs/quality-report-v1.md. Download the plain-text UTF-8 files
of eBooks 2944, 2945, 205 and 1022 from gutenberg.org into one directory as
pg2944.txt, pg2945.txt, pg205.txt and pg1022.txt.

Usage: python3 -I build_corpus.py <gutenberg dir> <out.json> [--classic] [--copies N]

--classic writes Classic-editor markup instead of blocks: paragraphs as text
between blank lines, headings and quotes as plain HTML. --copies N repeats the
corpus N times with "(copy n)" added to later titles, for timing only.
"""
import html
import json
import re
import sys
from datetime import datetime, timedelta

SRC = sys.argv[1]
OUT = sys.argv[2]
CLASSIC = '--classic' in sys.argv
COPIES = int(sys.argv[sys.argv.index('--copies') + 1]) if '--copies' in sys.argv else 1

BOOKS = {
    'pg2944.txt': ('Ralph Waldo Emerson, Essays: First Series (1841)', 2944),
    'pg2945.txt': ('Ralph Waldo Emerson, Essays: Second Series (1844)', 2945),
    'pg205.txt': ('Henry David Thoreau, Walden (1854)', 205),
    'pg1022.txt': ('Henry David Thoreau, Walking (1862)', 1022),
}

# (file, title, regex for the line that starts the text, max posts)
ESSAYS = [
    ('pg2944.txt', 'Self-Reliance', r'^SELF-RELIANCE$', 4),
    ('pg2944.txt', 'Compensation', r'^COMPENSATION$', 3),
    ('pg2944.txt', 'Love', r'^LOVE$', 2),
    ('pg2944.txt', 'Friendship', r'^FRIENDSHIP$', 3),
    ('pg2944.txt', 'Prudence', r'^PRUDENCE$', 2),
    ('pg2944.txt', 'Heroism', r'^HEROISM$', 2),
    ('pg2944.txt', 'Circles', r'^CIRCLES$', 2),
    ('pg2944.txt', 'Art', r'^ART$', 2),
    ('pg2945.txt', 'Experience', r'^II\. EXPERIENCE\.$', 3),
    ('pg2945.txt', 'Gifts', r'^V\. GIFTS\.$', 1),
    ('pg2945.txt', 'Nature', r'^VI\. NATURE\.$', 3),
    ('pg2945.txt', 'Manners', r'^IV\. MANNERS\.$', 2),
    ('pg205.txt', 'Economy', r'^Economy$', 4),
    ('pg205.txt', 'Where I Lived, and What I Lived For', r'^Where I Lived, and What I Lived For$', 2),
    ('pg205.txt', 'Reading', r'^Reading$', 2),
    ('pg205.txt', 'Sounds', r'^Sounds$', 2),
    ('pg205.txt', 'Solitude', r'^Solitude$', 2),
    ('pg205.txt', 'Visitors', r'^Visitors$', 1),
    ('pg205.txt', 'The Bean-Field', r'^The Bean-Field$', 2),
    ('pg205.txt', 'The Ponds', r'^The Ponds$', 3),
    ('pg205.txt', 'Higher Laws', r'^Higher Laws$', 2),
    ('pg205.txt', 'Brute Neighbors', r'^Brute Neighbors$', 2),
    ('pg205.txt', 'Winter Animals', r'^Winter Animals$', 1),
    ('pg205.txt', 'Spring', r'^Spring$', 2),
    ('pg205.txt', 'Conclusion', r'^Conclusion$', 2),
    ('pg1022.txt', 'Walking', r'^WALKING$', 4),
]

TARGET_WORDS = 1100
MAX_WORDS = 1600


def body(name):
    """Lines of a Gutenberg file between its START and END markers."""
    text = open(f'{SRC}/{name}', encoding='utf-8').read().replace('\r', '')
    start = text.index('*** START OF')
    start = text.index('\n', start) + 1
    end = text.index('*** END OF')
    return text[start:end].split('\n')


def essay_lines(lines, pattern, all_patterns):
    """Lines from the last match of pattern (titles repeat after epigraphs) to the next essay."""
    rx = re.compile(pattern)
    # Contents lists are indented; chapter and essay headings are not.
    hits = [i for i, l in enumerate(lines) if l == l.lstrip() and rx.match(l.strip())]
    first = hits[0]
    # Section ends at the next line that starts any other essay or a roman numeral heading.
    stops = re.compile(r'^([IVX]+\.( [A-Z].*)?|THE END)$')
    chapter_names = {p for p in all_patterns if p != pattern}
    end = len(lines)
    for j in range(first + 1, len(lines)):
        s = lines[j].strip()
        if j > hits[-1] and (any(re.match(p, s) for p in chapter_names) or stops.match(s)):
            end = j
            break
    return lines[first + 1:end]


def inline(text):
    """Escapes text as HTML and turns _underscored_ words into <em>."""
    text = html.escape(text, quote=False)
    return re.sub(r'_([^_]+)_', r'<em>\1</em>', text)


def blocks(lines):
    """Groups lines into paragraphs, verse quotes and quote citations."""
    paras, cur = [], []
    for l in lines + ['']:
        if l.strip() == '':
            if cur:
                paras.append(cur)
            cur = []
        else:
            cur.append(l)
    out = []
    for p in paras:
        stripped = [l.strip() for l in p]
        joined = ' '.join(stripped)
        if len(p) == 1 and re.fullmatch(r"[A-Z][A-Z \-,\.\'’IVX]+\.?", stripped[0]):
            continue  # an essay or chapter title line
        indented = all(l.startswith(' ') for l in p)
        short = len(p) >= 2 and max(len(l) for l in stripped) < 58
        if indented or short:
            out.append(('quote', stripped))
        elif len(p) == 1 and len(joined) < 70 and out and out[-1][0] == 'quote':
            out.append(('cite', joined))
        else:
            out.extend(('p', piece) for piece in split_long(joined))
    return out


def split_long(text, limit=400, size=200):
    """Some texts have no paragraph breaks; break very long runs at sentence ends."""
    if len(text.split()) <= limit:
        return [text]
    pieces, cur = [], []
    for sentence in re.split(r'(?<=[.!?])\s+(?=[A-Z“])', text):
        cur.append(sentence)
        if len(' '.join(cur).split()) >= size:
            pieces.append(' '.join(cur))
            cur = []
    if cur:
        pieces.append(' '.join(cur))
    return pieces


def render(parts):
    """Renders paragraphs and quotes as block markup."""
    html_out = []
    i = 0
    while i < len(parts):
        kind, val = parts[i]
        if kind == 'quote':
            inner = '<br>'.join(inline(l) for l in val)
            cite = ''
            if i + 1 < len(parts) and parts[i + 1][0] == 'cite':
                cite = f'<cite>{inline(parts[i + 1][1])}</cite>'
                i += 1
            html_out.append(
                '<!-- wp:quote -->\n<blockquote class="wp-block-quote"><!-- wp:paragraph -->\n'
                f'<p>{inner}</p>\n<!-- /wp:paragraph -->{cite}</blockquote>\n<!-- /wp:quote -->'
            )
        elif kind == 'cite':
            html_out.append(f'<!-- wp:paragraph -->\n<p><em>{inline(val)}</em></p>\n<!-- /wp:paragraph -->')
        else:
            html_out.append(f'<!-- wp:paragraph -->\n<p>{inline(val)}</p>\n<!-- /wp:paragraph -->')
        i += 1
    return '\n\n'.join(html_out)


def words(part):
    """Counts the words in one paragraph or quote."""
    kind, val = part
    return len((' '.join(val) if kind == 'quote' else val).split())


def classic(content):
    """Converts block markup to what the Classic editor stores."""
    out = []
    for block in (b.strip() for b in content.split('\n\n') if b.strip()):
        if block.startswith('<!-- wp:paragraph'):
            out.append(re.sub(r'(?s)^<!-- wp:paragraph -->\s*<p>(.*)</p>\s*<!-- /wp:paragraph -->$', r'\1', block))
        else:
            block = re.sub(r'<!-- /?wp:[^>]*-->\n?', '', block)
            out.append(block.replace(' class="wp-block-quote"', '').replace(' class="wp-block-heading"', '').strip())
    return '\n\n'.join(out)


def main():
    """Builds the corpus and prints each post's length."""
    cache = {}
    posts = []
    for name, title, pattern, max_posts in ESSAYS:
        lines = cache.setdefault(name, body(name))
        parts = blocks(essay_lines(lines, pattern, [e[2] for e in ESSAYS if e[0] == name]))
        chunks, cur, count = [], [], 0
        for part in parts:
            w = words(part)
            if cur and count + w > MAX_WORDS and count >= TARGET_WORDS * 0.6:
                chunks.append(cur)
                cur, count = [], 0
            cur.append(part)
            count += w
            if count >= TARGET_WORDS and part[0] == 'p':
                chunks.append(cur)
                cur, count = [], 0
        if cur and count > 300:
            chunks.append(cur)
        chunks = chunks[:max_posts]
        book, number = BOOKS[name]
        for n, chunk in enumerate(chunks, 1):
            post_title = title if len(chunks) == 1 else f'{title}, part {n}'
            heading = (
                '<!-- wp:heading -->\n'
                f'<h2 class="wp-block-heading">From {html.escape(title)}</h2>\n<!-- /wp:heading -->'
            )
            source = (
                '<!-- wp:paragraph -->\n'
                f'<p><em>Source: {html.escape(book)}. Public domain text from Project Gutenberg eBook #{number}.</em></p>\n'
                '<!-- /wp:paragraph -->'
            )
            posts.append({
                'title': post_title,
                'content': heading + '\n\n' + render(chunk) + '\n\n' + source,
                'words': sum(words(p) for p in chunk),
                'essay': title,
                'book': book,
                'quotes': sum(1 for p in chunk if p[0] == 'quote'),
            })
    start = datetime(2024, 1, 8, 9, 0)
    for i, p in enumerate(posts):
        p['date'] = (start + timedelta(days=11 * i)).strftime('%Y-%m-%d %H:%M:%S')
    if CLASSIC:
        for p in posts:
            p['content'] = classic(p['content'])
    posts += [dict(p, title=f"{p['title']} (copy {k})") for k in range(1, COPIES) for p in posts]
    json.dump(posts, open(OUT, 'w'), indent=1, ensure_ascii=False)
    print(len(posts), 'posts,', sum(p['words'] for p in posts), 'words')
    for p in posts:
        print(f"{p['words']:5d} q{p['quotes']} {p['title']}")


main()
