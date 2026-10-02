"""Find self::/static:: calls with no matching method definition, and
BFTD_X::method() calls where BFTD_X is one of ours and the method is absent.
This is the check that would have caught assign_field() before shipping."""
import re, os, sys, collections

files = sorted(f for f in os.listdir('includes') if f.endswith('.php'))
files += ['bf-tutoring-dashboard.php']

defs = collections.defaultdict(set)   # class -> methods
src  = {}
for f in files:
    path = f if f.endswith('bf-tutoring-dashboard.php') else os.path.join('includes', f)
    s = open(path).read()
    src[path] = s
    cls = re.search(r'^class\s+(\w+)', s, re.M)
    if not cls: continue
    name = cls.group(1)
    for m in re.finditer(r'function\s+(\w+)\s*\(', s):
        defs[name].add(m.group(1))

problems = []
for path, s in src.items():
    cls = re.search(r'^class\s+(\w+)', s, re.M)
    own = cls.group(1) if cls else None
    for m in re.finditer(r'\b(self|static|BFTD_\w+)::(\w+)\s*\(', s):
        target, meth = m.group(1), m.group(2)
        klass = own if target in ('self', 'static') else target
        if klass not in defs:      # not one of ours / not parsed
            continue
        if meth in defs[klass]:
            continue
        line = s[:m.start()].count('\n') + 1
        problems.append('%s:%d  %s::%s() is called but never defined' % (path, line, klass, meth))

# Every BFTD_* class a file uses must actually be loaded. Defining a class and
# forgetting to add it to the bootstrap's require list produces a fatal error
# only on the screens that touch it, which can be none of the ones you tested.
boot = open('bf-tutoring-dashboard.php').read()
m = re.search(r"foreach \( array\((.*?)\) as \$file \)", boot, re.S)
required = set(re.findall(r"'([a-z0-9-]+)'", m.group(1))) if m else set()

file_of = {}
for f in files:
    path = f if f.endswith('bf-tutoring-dashboard.php') else os.path.join('includes', f)
    cls = re.search(r'^class\s+(\w+)', src[path], re.M)
    if cls:
        file_of[cls.group(1)] = os.path.basename(path)

for name, fname in sorted(file_of.items()):
    slug = fname[len('class-bftd-'):-len('.php')] if fname.startswith('class-bftd-') else None
    if slug and slug not in required:
        problems.append('bf-tutoring-dashboard.php  %s is defined in %s but never required' % (name, fname))

for name in sorted(set(re.findall(r'\b(BFTD_\w+)::', ''.join(src.values())))):
    if name not in file_of and name not in defs:
        problems.append('%s is used but no file defines it' % name)

# A callback handed to WordPress is a call too, just one that happens later.
# array( __CLASS__, 'box_number' ) naming a method that no longer exists is a
# fatal the moment the hook fires, and nothing in the source reads as a call,
# so the check above walked straight past it. It cost an activity screen its
# Publish button and its track selector for two releases: the box was deleted,
# the add_meta_box line registering it was not.
for path, s in src.items():
    cls = re.search(r'^class\s+(\w+)', s, re.M)
    own = cls.group(1) if cls else None
    for m in re.finditer(r"array\(\s*(__CLASS__|self::class|'(?:BFTD_\w+)')\s*,\s*'(\w+)'\s*\)", s):
        target, meth = m.group(1), m.group(2)
        klass = own if target in ('__CLASS__', 'self::class') else target.strip("'")
        if klass not in defs:
            continue
        if meth in defs[klass]:
            continue
        line = s[:m.start()].count('\n') + 1
        problems.append('%s:%d  %s::%s is handed to a hook but never defined' % (path, line, klass, meth))

for p in problems: print('FAIL ' + p)
print(('FAIL: %d missing method(s)' % len(problems)) if problems else 'PASS: every self::/BFTD_*:: call and hook callback resolves')
sys.exit(1 if problems else 0)
