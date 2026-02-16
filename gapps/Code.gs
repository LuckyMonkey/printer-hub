function doGet() {
  return ContentService
    .createTextOutput(JSON.stringify({ ok: true, service: 'printer-hub-gapps' }))
    .setMimeType(ContentService.MimeType.JSON);
}

function doPost(e) {
  try {
    var raw = (e && e.postData && e.postData.contents) ? e.postData.contents : '{}';
    var data = JSON.parse(raw);

    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var sheetName = 'printer_batches';
    var sheet = ss.getSheetByName(sheetName);
    if (!sheet) {
      sheet = ss.insertSheet(sheetName);
    }

    if (sheet.getLastRow() === 0) {
      sheet.appendRow([
        'received_at',
        'batch_id',
        'printer',
        'printer_queue',
        'symbology',
        'count',
        'csv',
        'carriage_return',
        'values_json',
        'created_at',
        'raw_json'
      ]);
    }

    sheet.appendRow([
      new Date(),
      data.batchId || '',
      data.printer || '',
      data.printerQueue || '',
      data.symbology || '',
      data.count || '',
      data.csv || '',
      data.carriageReturn || '',
      JSON.stringify(data.values || []),
      data.createdAt || '',
      raw
    ]);

    return ContentService
      .createTextOutput(JSON.stringify({ ok: true, rows: 1 }))
      .setMimeType(ContentService.MimeType.JSON);
  } catch (err) {
    return ContentService
      .createTextOutput(JSON.stringify({ ok: false, error: String(err) }))
      .setMimeType(ContentService.MimeType.JSON);
  }
}
