import os
import re

api_dir = '/Users/marcovanzo/MV Consulting ERP/api'

# We need to replace:
# 1. require_once __DIR__ . '/../Shared/Logger.php'; -> require_once __DIR__ . '/../Shared/Audit.php';
# 2. require_once __DIR__ . '/Shared/Logger.php'; -> require_once __DIR__ . '/Shared/Audit.php';
# 3. Logger::logAction(arg1, arg2, arg3, arg4) -> Audit::log(arg1, arg2, arg3, null, null, arg4)
# Note: some calls only have 1, 2, or 3 arguments! We must handle those properly.

def refactor_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    # Replacements for imports
    content = content.replace("require_once __DIR__ . '/../Shared/Logger.php';", "require_once __DIR__ . '/../Shared/Audit.php';")
    content = content.replace("require_once __DIR__ . '/Shared/Logger.php';", "require_once __DIR__ . '/Shared/Audit.php';")

    # Regex to match Logger::logAction with any arguments.
    # This regex assumes args are well-balanced or simply split by comma.
    # Because PHP can have complex args, a robust regex approach is:
    def replacer(m):
        args_str = m.group(1)
        # Split by comma, but be careful with nested arrays/functions.
        # Actually, in MV Consulting ERP the calls are very simple strings/variables.
        # So splitting by top-level commas works.
        # Let's do a simple parse:
        args = []
        depth = 0
        current = ""
        for char in args_str:
            if char in "([": depth += 1
            elif char in ")]": depth -= 1
            
            if char == ',' and depth == 0:
                args.append(current.strip())
                current = ""
            else:
                current += char
        if current.strip():
            args.append(current.strip())
            
        # Audit::log signature: string $action, string $tableName, ?string $recordId = null, ?array $before = null, ?array $after = null, ?array $details = null
        # Logger::logAction signature: $action, $tableName = null, $recordId = null, $details = null
        action = args[0] if len(args) > 0 else 'null'
        tableName = args[1] if len(args) > 1 else 'null'
        recordId = args[2] if len(args) > 2 else 'null'
        details = args[3] if len(args) > 3 else 'null'
        
        # We rewrite it to Audit::log
        return f"Audit::log({action}, {tableName}, {recordId}, null, null, {details})"
        
    pattern = re.compile(r'Logger::logAction\s*\((.*?)\);', re.DOTALL)
    new_content = pattern.sub(replacer, content)

    if new_content != content:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(new_content)
        print(f"Refactored: {filepath}")

for root, dirs, files in os.walk(api_dir):
    for file in files:
        if file.endswith('.php'):
            refactor_file(os.path.join(root, file))

print("Refactoring complete.")
