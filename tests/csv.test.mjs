import test from 'node:test';
import assert from 'node:assert/strict';
import { csvCell, toCsv } from '../csv.js';

test('CSV cells neutralize spreadsheet formula prefixes', () => {
  for (const value of ['=1+1', '+SUM(A1:A2)', '-1+2', '@SUM(A1)', '  =1+1', '\\t=1+1']) {
    assert.equal(csvCell(value), '"' + "'" + value.replace(/"/g, '""') + '"');
  }
});

test('CSV cells preserve ordinary text and escape quotes', () => {
  assert.equal(csvCell('Project "Alpha"'), '"Project ""Alpha"""');
  assert.equal(csvCell('ordinary text'), '"ordinary text"');
  assert.equal(csvCell(null), '""');
});

test('CSV rows use comma separators and CRLF line endings', () => {
  assert.equal(toCsv([['Name', 'Value'], ['Alpha', '=1+1']]), '"Name","Value"\\r\\n"Alpha","\'=1+1"');
});
