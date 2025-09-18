import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const startPath = path.resolve(__dirname, '..'); // eine Ebene höher
const ignoreDirs = ['assets', 'tools'].map(s => s.toLowerCase());

const argMax = parseInt(process.argv[2], 10);
const envMax = parseInt(process.env.BLOG_MAX_LINES, 10);
const MAX_LINES = Number.isInteger(argMax) ? argMax : (Number.isInteger(envMax) ? envMax : 1000);

function shouldIgnoreDir(name) {
  return ignoreDirs.includes(name.toLowerCase());
}

function exportEntries(dir, output = []) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const e of entries) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (shouldIgnoreDir(e.name)) continue;
      exportEntries(full, output);
    } else if (e.isFile()) {
      const rel = path.relative(startPath, full);
      const content = fs.readFileSync(full, 'utf8');
      const block = `Datei: ${rel}\n${content}\n`;
      output.push(block);
    }
  }
  return output;
}

const blocks = exportEntries(startPath);
const lineCounts = blocks.map(b => b.split('\n').length);
const totalLines = lineCounts.reduce((a, b) => a + b, 0);
const parts = Math.max(1, Math.ceil(totalLines / MAX_LINES));
const targetPerPart = Math.ceil(totalLines / parts);

let currentLines = 0;
let currentPart = 1;
let buffer = [];

function flushPart(partIndex, content) {
  const file = path.join(startPath, `/tools/export_part-${String(partIndex).padStart(2, '0')}.txt`);
  fs.writeFileSync(file, content.join('\n'), 'utf8');
  return file;
}

const writtenFiles = [];
for (let i = 0; i < blocks.length; i++) {
  const block = blocks[i];
  const lines = lineCounts[i];

  if (currentLines + lines > targetPerPart && buffer.length > 0) {
    const file = flushPart(currentPart, buffer);
    writtenFiles.push(file);
    currentPart++;
    buffer = [];
    currentLines = 0;
  }

  if (lines > targetPerPart && buffer.length === 0) {
    const linesArr = block.split('\n');
    let chunk = [];
    let chunkCount = 0;
    for (const ln of linesArr) {
      chunk.push(ln);
      chunkCount++;
      if (chunkCount >= targetPerPart) {
        const file = flushPart(currentPart, [chunk.join('\n')]);
        writtenFiles.push(file);
        currentPart++;
        chunk = [];
        chunkCount = 0;
      }
    }
    if (chunk.length > 0) {
      buffer.push(chunk.join('\n'));
      currentLines += chunkCount;
    }
  } else {
    buffer.push(block);
    currentLines += lines;
  }
}

if (buffer.length > 0) {
  const file = flushPart(currentPart, buffer);
  writtenFiles.push(file);
}

console.log(`✅ Export fertig (${writtenFiles.length} Teile, max ~${targetPerPart} Zeilen/Teil, Limit ${MAX_LINES}):`);
writtenFiles.forEach(f => console.log(` - ${f}`));
