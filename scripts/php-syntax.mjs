import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import parserPackage from 'php-parser';

const Engine = parserPackage.Engine || parserPackage.default?.Engine;
const parser = new Engine({ parser: { php7: true, suppressErrors: false }, ast: { withPositions: true } });
const root = path.resolve(import.meta.dirname, '..');
const excluded = new Set(['node_modules', '.npm-cache', 'assets']);
const files = [];

function walk(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    if (excluded.has(entry.name)) continue;
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) walk(target);
    else if (entry.name.endsWith('.php')) files.push(target);
  }
}

walk(root);
let failed = false;
for (const file of files) {
  try {
    parser.parseCode(fs.readFileSync(file, 'utf8'), file);
  } catch (error) {
    failed = true;
    console.error(`${path.relative(root, file)}: ${error.message}`);
  }
}
if (failed) process.exit(1);
console.log(`Parsed ${files.length} PHP files successfully.`);
