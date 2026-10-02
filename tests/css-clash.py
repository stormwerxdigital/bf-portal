"""No two blocks of the stylesheet may claim the same class and disagree
about what it is.

`.att` was an attachment chip — display:inline-flex, a max-width, a hover
border. A new attendance panel took the same name for a four-column grid two
hundred lines earlier in the file, and the chip's rule won: the panel came out
as a 330px strip with "16 days since the last session" stacked one word per
line. Nothing errored, no test failed, and the source read correctly in both
places.

So: a class may be written in several rules, but only where those rules are
part of one block of the file. Two definitions far apart, both setting
`display`, is two features that have not noticed each other.
"""
import re, sys, collections

FAR = 60          # lines apart before two mentions are treated as unrelated
PROP = 'display'  # the property that makes a disagreement visible

fail = []
for path in ('assets/css/bftd-report.css', 'assets/css/bftd-admin.css'):
    src = open(path).read()
    src = re.sub(r'/\*[\s\S]*?\*/', lambda m: re.sub(r'\S', ' ', m.group(0)), src)

    # class -> [(line, declared display value)]
    seen = collections.defaultdict(list)
    for m in re.finditer(r'([^{}]+)\{([^{}]*)\}', src):
        sel, body = m.group(1), m.group(2)
        d = re.search(r'(?:^|;)\s*display\s*:\s*([^;]+)', body)
        if not d:
            continue
        line = src[:m.start()].count('\n') + 1
        # The thing being styled is the LAST COMPOUND of the selector, and
        # only when that compound is a class.
        #
        # Taking the last class anywhere in the selector was wrong twice over:
        # `.bf-report table` styles tables, not `.bf-report`, and
        # `.bftd-stu-acts a` styles the links, not the container. Both were
        # reported as a class disagreeing with itself, which is a checker
        # telling somebody to contort correct CSS.
        for one in sel.split(','):
            last = re.split(r'[\s>+~]+', one.strip())[-1]
            if not last.startswith('.'):
                continue
            names = re.findall(r'\.([a-zA-Z0-9_-]+)', last)
            if not names:
                continue
            # skip a compound like .att-t.is-warn: it is the same thing, qualified
            seen[names[-1]].append((line, d.group(1).strip()))

    for name, hits in sorted(seen.items()):
        if len(hits) < 2:
            continue
        for i in range(len(hits) - 1):
            a, b = hits[i], hits[i + 1]
            if b[0] - a[0] <= FAR:
                continue
            if a[1] == b[1]:
                continue
            # Hiding something is a normal override: a print block, a media
            # query, a collapsed state. Two rules that both lay something out,
            # differently, are two features that have not met.
            if 'none' in (a[1], b[1]):
                continue
            fail.append('%s: .%s is display:%s on line %d and display:%s on line %d'
                        % (path, name, a[1], a[0], b[1], b[0]))

for f in fail:
    print('FAIL ' + f)
print(('FAIL: %d clashing class name(s)' % len(fail)) if fail
      else 'PASS: no class is two different things in one stylesheet')
sys.exit(1 if fail else 0)
