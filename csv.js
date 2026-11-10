/** CSV helpers that neutralize spreadsheet formula injection. */
export function csvCell(value) {
  let text = String(value ?? '');
  // Spreadsheet applications may evaluate cells beginning with formula markers.
  // Include leading control/space characters in the check to prevent simple bypasses.
  if (/^[\\u0000-\\u0020]*[=+\\-@]/u.test(text)) text = "'" + text;
  return '"' + text.replace(/"/g, '""') + '"';
}

export function toCsv(rows) {
  return rows.map((row) => row.map(csvCell).join(',')).join('\\r\\n');
}
