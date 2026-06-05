import json

with open('/Users/benjaminmarchand/.gemini/antigravity-ide/brain/8a86a6e2-2a38-48c0-8b8e-99ed73949798/.system_generated/steps/657/content.md', 'r') as f:
    lines = f.readlines()

json_str = ""
for line in lines:
    if line.strip().startswith('{"openapi":'):
        json_str = line.strip()
        break

if json_str:
    data = json.loads(json_str)
    schema = data.get("components", {}).get("schemas", {}).get("ProcessingRule", {})
    print(json.dumps(schema, indent=2))
else:
    print("Could not find JSON in content.md")
