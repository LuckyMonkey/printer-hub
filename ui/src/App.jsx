import React, { useEffect, useMemo, useState } from 'react';
import printersSprite from './assets/printers.png';

const PANEL_CLASS = 'retro-window p-8 sm:p-10';
const LABEL_CLASS = 'retro-label';
const INPUT_CLASS = 'retro-input mt-2';
const BUTTON_CLASS = 'retro-button px-6 py-3 text-[17px] leading-none';
const SUBTLE_BUTTON_CLASS = 'retro-button px-4 py-2 text-[15px] leading-none';
const SPRITE_SIZE = 32;
const SPRITE_FRAME = {
  hp: 0,
  brother: 1,
  zebra: 2,
  scanner: 3,
  usbDown: 4,
  usbUp: 5,
  ethDown: 6,
  ethUp: 7,
};

function detectRoute() {
  const pathname = window.location.pathname || '/ui/';
  const withoutBase = pathname.replace(/^\/ui/, '') || '/';
  const normalized = withoutBase.endsWith('/') && withoutBase !== '/'
    ? withoutBase.slice(0, -1)
    : withoutBase;

  if (normalized === '/printers') return '/printers';
  if (normalized.startsWith('/printers/')) return normalized;
  return '/printers';
}

function goRoute(route, setRoute) {
  const path = `/ui${route}`;
  window.history.pushState({}, '', path);
  setRoute(route);
}

async function api(path, options = {}) {
  const res = await fetch(path, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.error || 'Request failed');
  }
  return data;
}

function normalizeQrLinkUrl(raw) {
  const value = String(raw || '').trim();
  if (!value) return '';
  if (/^[a-z][a-z0-9+\-.]*:\/\//i.test(value)) {
    try {
      return new URL(value).toString();
    } catch {
      return '';
    }
  }

  const prefersHttp = /(^|\.)local(?::\d+)?(\/|$)/i.test(value)
    || /^localhost(?::\d+)?(\/|$)/i.test(value)
    || /^\d{1,3}(?:\.\d{1,3}){3}(?::\d+)?(\/|$)/.test(value)
    || /:\d+(\/|$)/.test(value);

  try {
    return new URL(`${prefersHttp ? 'http' : 'https'}://${value}`).toString();
  } catch {
    return '';
  }
}

function formatStatus(status) {
  if (status === 'sent') return 'printed';
  if (status === 'sending') return 'sending';
  if (status === 'queued') return 'queued';
  if (status === 'error') return 'error';
  return status || 'unknown';
}

function validateForm(printer, form) {
  if (!form.barcodeValue.trim()) {
    return 'Barcode value is required.';
  }

  const copies = Number(form.copies);
  if (!Number.isInteger(copies) || copies < 1 || copies > 250) {
    return 'Copies must be an integer from 1 to 250.';
  }

  if (form.barcodeType === 'UPCA' && !/^\d{11,12}$/.test(form.barcodeValue.trim())) {
    return 'UPCA requires 11 or 12 digits.';
  }

  const maxBarcodeLength = Number(printer?.capabilities?.maxBarcodeLength || 120);
  if (form.barcodeValue.trim().length > maxBarcodeLength) {
    return `Barcode value exceeds max length (${maxBarcodeLength}).`;
  }

  const maxTextLength = Number(printer?.capabilities?.maxTextLine1Length || 120);
  if ((form.textLine1 || '').trim().length > maxTextLength) {
    return `Text line 1 exceeds max length (${maxTextLength}).`;
  }

  if (printer?.printerId === 'zebra-zp505' && form.labelType === 'business-card') {
    if (form.barcodeType !== 'QR') {
      return 'Business card Zebra labels require barcode type QR.';
    }
    if (!(form.textLine1 || '').trim()) {
      return 'Business card Zebra labels require a name in Text Line 1.';
    }
    if (!normalizeQrLinkUrl(form.barcodeValue)) {
      return 'Business card Zebra labels require a valid QR link URL.';
    }
  }

  return null;
}

function splitBatchValues(raw) {
  return String(raw || '')
    .split(/[\r\n,]+/)
    .map((value) => value.trim())
    .filter(Boolean);
}

function normalizeUpcaValue(value) {
  if (!/^\d{11,12}$/.test(value)) {
    return null;
  }

  if (value.length === 12) {
    return value;
  }

  let odd = 0;
  let even = 0;
  for (let index = 0; index < 11; index += 1) {
    const digit = Number(value[index]);
    if (index % 2 === 0) {
      odd += digit;
    } else {
      even += digit;
    }
  }

  const sum = (odd * 3) + even;
  const checkDigit = (10 - (sum % 10)) % 10;
  return `${value}${checkDigit}`;
}

function pickDefaultBatchType(printer) {
  const supported = Array.isArray(printer?.capabilities?.barcodeTypes)
    ? printer.capabilities.barcodeTypes
    : [];
  const preferred = String(printer?.batch?.defaultBarcodeType || '').trim();
  if (preferred && supported.includes(preferred)) {
    return preferred;
  }
  return supported[0] || 'CODE128';
}

function recommendedBatchTypes(printer) {
  const supported = Array.isArray(printer?.capabilities?.barcodeTypes)
    ? printer.capabilities.barcodeTypes
    : [];
  const preferred = Array.isArray(printer?.batch?.recommendedBarcodeTypes)
    ? printer.batch.recommendedBarcodeTypes.filter((type) => supported.includes(type))
    : [];

  return [...new Set([...preferred, ...supported])];
}

function analyzeBatchInput(input, barcodeType, batchConfig) {
  const values = splitBatchValues(input);
  const maxValues = Math.max(1, Number(batchConfig?.maxValues || 120));
  const chunkSize = Math.max(1, Number(batchConfig?.chunkSize || 1));
  const invalidValues = [];
  const normalizedValues = values.map((value) => {
    if (barcodeType !== 'UPCA') {
      return value;
    }

    const normalized = normalizeUpcaValue(value);
    if (!normalized) {
      invalidValues.push(value);
      return value;
    }

    return normalized;
  });

  const counts = new Map();
  for (const value of normalizedValues) {
    counts.set(value, (counts.get(value) || 0) + 1);
  }

  const duplicates = [...counts.entries()]
    .filter(([, count]) => count > 1)
    .map(([value, count]) => ({ value, count }));

  const printableValues = barcodeType === 'UPCA' ? normalizedValues : values;
  const count = printableValues.length;
  const chunkCount = count === 0 ? 0 : Math.ceil(count / chunkSize);
  const remainder = count === 0 ? 0 : count - ((chunkCount - 1) * chunkSize);

  return {
    values,
    printableValues,
    invalidValues,
    duplicates,
    count,
    maxValues,
    chunkSize,
    chunkCount,
    remainder,
    tooMany: count > maxValues,
    previewValues: printableValues.slice(0, 8),
    hiddenPreviewCount: Math.max(printableValues.length - 8, 0),
    submitInput: printableValues.join('\n'),
  };
}

function batchCapabilityLabel(printer) {
  const chunkSize = Math.max(1, Number(printer?.batch?.chunkSize || 1));
  if (printer?.printerId === 'zebra-zp505') {
    return `${chunkSize} labels per Zebra sheet`;
  }
  if (printer?.printerId === 'hp-envy-5055') {
    return `${chunkSize} labels per HP page`;
  }
  return 'Sequential single-label queue';
}

function batchActionLabel(printer, batchType) {
  if (printer?.printerId === 'zebra-zp505' && batchType === 'UPCA') {
    return 'Print Zebra UPC Batch';
  }
  if (printer?.printerId === 'zebra-zp505') {
    return 'Print Zebra Batch';
  }
  if (printer?.printerId === 'hp-envy-5055') {
    return 'Print HP Batch';
  }
  return 'Save + Print Batch';
}

function spriteForPrinter(printerId) {
  if (printerId === 'hp-envy-5055') return SPRITE_FRAME.hp;
  if (printerId === 'brother-ql820') return SPRITE_FRAME.brother;
  return SPRITE_FRAME.zebra;
}

function normalizeCupsQueues(payload) {
  if (!payload || !Array.isArray(payload.printers)) {
    return new Set();
  }

  return new Set(
    payload.printers
      .map((item) => String(item?.name || '').trim())
      .filter(Boolean)
  );
}

function queueConnected(printer, cupsQueues) {
  if (printer.transport !== 'cups') {
    const host = String(printer.host || '').trim().toLowerCase();
    if (!host) return false;
    if (['127.0.0.1', 'localhost', '::1', '0.0.0.0'].includes(host)) return false;
    return true;
  }
  const queue = String(printer.cupsQueueName || '').trim();
  if (!queue) {
    return false;
  }
  return cupsQueues.has(queue);
}

function connectionSprite(printer, isConnected) {
  const isUsb = printer.printerId === 'zebra-zp505';
  if (isUsb) {
    return isConnected ? SPRITE_FRAME.usbUp : SPRITE_FRAME.usbDown;
  }
  return isConnected ? SPRITE_FRAME.ethUp : SPRITE_FRAME.ethDown;
}

function SpriteIcon({ frame, label, className = '' }) {
  return (
    <span
      role="img"
      aria-label={label}
      title={label}
      className={`sprite-icon ${className}`.trim()}
    >
      <img
        src={printersSprite}
        alt=""
        aria-hidden="true"
        className="sprite-icon-sheet"
        style={{ transform: `translateY(${frame * -SPRITE_SIZE}px)` }}
      />
    </span>
  );
}

function PrinterList({ printers, cupsQueues, onOpen }) {
  return (
    <section className={PANEL_CLASS}>
      <h2 className="font-display text-5xl leading-none text-[#1d1d1d]">Printer Pages</h2>
      <p className="mt-4 max-w-4xl text-base text-[#2a2a2a]">
        Open a printer page to choose label type, provide barcode input, run a test print, and send production jobs.
      </p>

      <div className="mt-8 grid gap-6 lg:grid-cols-3">
        {printers.map((printer) => (
          <button
            key={printer.printerId}
            className="retro-window p-6 text-left transition hover:translate-y-[-1px]"
            onClick={() => onOpen(`/printers/${printer.printerId}`)}
          >
            <span className="mb-3 flex items-center gap-3">
              <SpriteIcon frame={spriteForPrinter(printer.printerId)} label={`${printer.displayName} icon`} />
              <span className="text-xl font-semibold text-[#181818]">{printer.displayName}</span>
            </span>
            <span className="mt-1 block text-sm text-[#2e2e2e]">Transport: {printer.transport}</span>
            <span className="mt-2 block text-sm text-[#22456f]">Batch: {batchCapabilityLabel(printer)}</span>
            <span className="mt-3 flex items-center gap-2 text-xs font-mono text-[#24333f]">
              <SpriteIcon
                frame={connectionSprite(printer, queueConnected(printer, cupsQueues))}
                label="Connection status"
                className="scale-90"
              />
              {queueConnected(printer, cupsQueues)
                ? 'Link ready'
                : (printer.transport === 'cups'
                  ? `Queue missing: ${printer.cupsQueueName || 'unset'}`
                  : `Socket target invalid: ${printer.host || 'unset'}:${printer.port || 9100}`)}
            </span>
            <span className="mt-4 block font-mono text-xs text-[#1a3f7a]">
              Label Types: {printer.labelTypes.map((t) => t.id).join(', ')}
            </span>
          </button>
        ))}
      </div>
    </section>
  );
}

function PrinterPage({ printer, cupsQueues, onBack }) {
  const defaultLabelType = printer.labelTypes[0]?.id || 'waco-id';
  const defaultBarcodeType = printer.capabilities?.barcodeTypes?.[0] || 'CODE128';
  const defaultBatchType = pickDefaultBatchType(printer);
  const batchEnabled = printer?.batch?.enabled !== false;
  const batchTypes = recommendedBatchTypes(printer);
  const batchChunkSize = Math.max(1, Number(printer?.batch?.chunkSize || 1));
  const batchMaxValues = Math.max(1, Number(printer?.batch?.maxValues || 120));
  const hasQueue = queueConnected(printer, cupsQueues);
  const singleModeEnabled = printer.printerId === 'brother-ql820' || printer.printerId === 'zebra-zp505';

  const [form, setForm] = useState({
    labelType: defaultLabelType,
    barcodeType: defaultBarcodeType,
    barcodeValue: '',
    textLine1: '',
    copies: 1,
  });

  const [message, setMessage] = useState('Ready.');
  const [job, setJob] = useState(null);
  const [printing, setPrinting] = useState(false);
  const [batchInput, setBatchInput] = useState('');
  const [batchType, setBatchType] = useState(defaultBatchType);
  const [batchMessage, setBatchMessage] = useState('Batch ready.');
  const [batchPrinting, setBatchPrinting] = useState(false);
  const [batchSummary, setBatchSummary] = useState(null);

  useEffect(() => {
    setForm({
      labelType: defaultLabelType,
      barcodeType: defaultBarcodeType,
      barcodeValue: '',
      textLine1: '',
      copies: 1,
    });
    setMessage('Ready.');
    setJob(null);
    setBatchInput('');
    setBatchType(defaultBatchType);
    setBatchMessage('Batch ready.');
    setBatchSummary(null);
  }, [printer.printerId, defaultLabelType, defaultBarcodeType, defaultBatchType]);

  useEffect(() => {
    if (printer.printerId === 'zebra-zp505' && form.labelType === 'business-card' && form.barcodeType !== 'QR') {
      setForm((prev) => ({ ...prev, barcodeType: 'QR' }));
    }
  }, [printer.printerId, form.labelType, form.barcodeType]);

  const batchAnalysis = useMemo(
    () => analyzeBatchInput(batchInput, batchType, printer.batch),
    [batchInput, batchType, printer.batch]
  );

  async function pollJob(jobId) {
    let attempts = 0;
    while (attempts < 20) {
      attempts += 1;
      const statusRes = await api(`/api/print/${jobId}`);
      const snapshot = statusRes.job || null;
      setJob(snapshot);

      if (!snapshot) {
        setMessage('Job status unavailable.');
        return;
      }

      if (snapshot.status === 'sent') {
        setMessage(`Printed (sent): ${jobId}`);
        return;
      }

      if (snapshot.status === 'error') {
        setMessage(`Error: ${snapshot.error || 'unknown error'}`);
        return;
      }

      await new Promise((resolve) => setTimeout(resolve, 1200));
    }

    setMessage(`Job ${jobId} is still processing.`);
  }

  async function submitCurrent(customPayload = null) {
    const nextPayload = customPayload || {
      printerId: printer.printerId,
      labelType: form.labelType,
      barcodeType: form.barcodeType,
      barcodeValue: form.labelType === 'business-card' && printer.printerId === 'zebra-zp505'
        ? normalizeQrLinkUrl(form.barcodeValue)
        : form.barcodeValue.trim(),
      textLine1: form.textLine1.trim(),
      copies: Number(form.copies),
    };

    const validationError = validateForm(printer, nextPayload);
    if (validationError) {
      setMessage(validationError);
      return;
    }

    setPrinting(true);
    setMessage('Queued...');

    try {
      const res = await api('/api/print', {
        method: 'POST',
        body: JSON.stringify(nextPayload),
      });

      const jobId = res.jobId;
      if (!jobId) {
        setMessage('Print API did not return a jobId.');
        return;
      }

      setMessage(`Sending... job ${jobId}`);
      await pollJob(jobId);
    } catch (err) {
      setMessage(err.message);
    } finally {
      setPrinting(false);
    }
  }

  async function onSubmit(event) {
    event.preventDefault();
    await submitCurrent();
  }

  async function runTestPrint() {
    const sample = printer.printerId === 'zebra-zp505'
      ? {
          printerId: printer.printerId,
          labelType: 'business-card',
          barcodeType: 'QR',
          barcodeValue: 'https://fridge.local/?go=printers',
          textLine1: 'Charlie',
          copies: 1,
        }
      : {
          printerId: printer.printerId,
          labelType: defaultLabelType,
          barcodeType: 'CODE128',
          barcodeValue: '051000568235',
          textLine1: 'Spaghettios',
          copies: 1,
        };

    setForm((prev) => ({
      ...prev,
      labelType: sample.labelType,
      barcodeType: sample.barcodeType,
      barcodeValue: sample.barcodeValue,
      textLine1: sample.textLine1,
      copies: sample.copies,
    }));

    await submitCurrent(sample);
  }

  async function submitBatch(event) {
    event.preventDefault();

    if (!batchEnabled) {
      setBatchMessage('Batch mode is unavailable for this printer.');
      return;
    }

    if (batchAnalysis.count === 0) {
      setBatchMessage('Batch barcode data is required.');
      return;
    }

    if (batchAnalysis.tooMany) {
      setBatchMessage(`Batch must contain 1 to ${batchMaxValues} barcode values.`);
      return;
    }

    if (batchAnalysis.invalidValues.length > 0) {
      setBatchMessage(`Fix invalid UPC-A values before printing: ${batchAnalysis.invalidValues.slice(0, 3).join(', ')}`);
      return;
    }

    setBatchPrinting(true);
    setBatchMessage('Saving and printing batch...');
    setBatchSummary(null);

    try {
      const res = await api('/api/batches/save-print-early', {
        method: 'POST',
        body: JSON.stringify({
          printerId: printer.printerId,
          labelType: form.labelType,
          barcodeType: batchType,
          input: batchAnalysis.submitInput,
        }),
      });

      setBatchSummary(res);
      setBatchMessage(`Batch ${res.batchId} saved. Sent: ${res.sentCount}, Errors: ${res.errorCount}.`);
    } catch (err) {
      setBatchMessage(err.message);
    } finally {
      setBatchPrinting(false);
    }
  }

  const statusText = useMemo(() => formatStatus(job?.status || ''), [job]);
  const zebraBusinessCardMode = printer.printerId === 'zebra-zp505' && form.labelType === 'business-card';
  const statusFrame = useMemo(() => {
    if (job?.status === 'error') {
      return connectionSprite(printer, false);
    }
    if (job?.status === 'sent') {
      return connectionSprite(printer, true);
    }
    if (!hasQueue) {
      return connectionSprite(printer, false);
    }
    return SPRITE_FRAME.scanner;
  }, [job?.status, hasQueue, printer]);

  const batchReady = batchEnabled
    && batchAnalysis.count > 0
    && !batchAnalysis.tooMany
    && batchAnalysis.invalidValues.length === 0;

  const batchReadinessMessage = (() => {
    if (!batchEnabled) {
      return 'Batch mode is disabled for this printer configuration.';
    }
    if (batchAnalysis.count === 0) {
      return printer?.batch?.helperText || 'Paste barcode values to prepare a batch.';
    }
    if (batchAnalysis.tooMany) {
      return `Reduce the list to ${batchMaxValues} values or fewer.`;
    }
    if (batchAnalysis.invalidValues.length > 0) {
      return `Found ${batchAnalysis.invalidValues.length} invalid UPC-A value${batchAnalysis.invalidValues.length === 1 ? '' : 's'}.`;
    }
    if (batchAnalysis.duplicates.length > 0) {
      return `Ready to print, but ${batchAnalysis.duplicates.length} value${batchAnalysis.duplicates.length === 1 ? ' is' : 's are'} duplicated.`;
    }
    return `Ready: ${batchAnalysis.count} value${batchAnalysis.count === 1 ? '' : 's'} across ${batchAnalysis.chunkCount} batch ${batchAnalysis.chunkCount === 1 ? 'job' : 'jobs'}.`;
  })();

  return (
    <section className={PANEL_CLASS}>
      <div className="flex flex-wrap items-center gap-3">
        <button className={SUBTLE_BUTTON_CLASS} onClick={onBack}>All Printers</button>
        <SpriteIcon frame={spriteForPrinter(printer.printerId)} label={`${printer.displayName} icon`} />
        <h2 className="font-display text-5xl leading-none text-[#1d1d1d]">{printer.displayName}</h2>
      </div>

      <p className="mt-4 text-sm text-[#2d2d2d]">Printer ID: <span className="font-mono">{printer.printerId}</span></p>
      <p className="mt-2 flex items-center gap-2 text-xs font-mono text-[#253643]">
        <SpriteIcon
          frame={connectionSprite(printer, hasQueue)}
          label="Connection status"
          className="scale-90"
        />
        {hasQueue
          ? 'Connection available'
          : (printer.transport === 'cups'
            ? `Connection unavailable: queue "${printer.cupsQueueName || 'unset'}" missing`
            : `Connection unavailable: socket host "${printer.host || 'unset'}:${printer.port || 9100}"`)}
      </p>

      <div className="mt-6 grid gap-4 lg:grid-cols-3">
        <div className="retro-window p-4">
          <p className={LABEL_CLASS}>Batch Shape</p>
          <p className="mt-2 text-base text-[#1d1d1d]">{batchCapabilityLabel(printer)}</p>
        </div>
        <div className="retro-window p-4">
          <p className={LABEL_CLASS}>Accepted Symbologies</p>
          <p className="mt-2 font-mono text-sm text-[#1d1d1d]">{printer.capabilities.barcodeTypes.join(', ')}</p>
        </div>
        <div className="retro-window p-4">
          <p className={LABEL_CLASS}>Batch Limit</p>
          <p className="mt-2 text-base text-[#1d1d1d]">Up to {batchMaxValues} values per submission</p>
        </div>
      </div>

      {singleModeEnabled ? (
        <>
          <form className="mt-8 grid gap-7 md:grid-cols-2" onSubmit={onSubmit}>
            <div>
              <label className={LABEL_CLASS}>Label Type</label>
              <select
                className={INPUT_CLASS}
                value={form.labelType}
                onChange={(e) => setForm((prev) => ({ ...prev, labelType: e.target.value }))}
              >
                {printer.labelTypes.map((item) => (
                  <option key={item.id} value={item.id}>{item.label} ({item.id})</option>
                ))}
              </select>
            </div>

            <div>
              <label className={LABEL_CLASS}>Barcode Type</label>
              <select
                className={INPUT_CLASS}
                value={form.barcodeType}
                onChange={(e) => setForm((prev) => ({ ...prev, barcodeType: e.target.value }))}
                disabled={zebraBusinessCardMode}
              >
                {printer.capabilities.barcodeTypes.map((type) => (
                  <option key={type} value={type}>{type}</option>
                ))}
              </select>
              {zebraBusinessCardMode ? (
                <p className="mt-2 text-xs text-[#2d2d2d]">Business cards lock Zebra into QR mode so the link renders reliably.</p>
              ) : null}
            </div>

            <div>
              <label className={`${LABEL_CLASS} flex items-center gap-2`}>
                <SpriteIcon frame={SPRITE_FRAME.scanner} label="Barcode scanner" className="scale-90" />
                {zebraBusinessCardMode ? 'QR Link URL' : 'Barcode Value'}
              </label>
              <input
                className={INPUT_CLASS}
                value={form.barcodeValue}
                onChange={(e) => setForm((prev) => ({ ...prev, barcodeValue: e.target.value }))}
                placeholder={zebraBusinessCardMode ? 'https://example.com/card' : 'Required'}
              />
              {zebraBusinessCardMode ? (
                <p className="mt-2 text-xs text-[#2d2d2d]">Use a full link or a bare host/path. The app will normalize it before print.</p>
              ) : null}
            </div>

            <div>
              <label className={LABEL_CLASS}>{zebraBusinessCardMode ? 'Name (required)' : 'Text Line 1 (optional)'}</label>
              <input
                className={INPUT_CLASS}
                value={form.textLine1}
                onChange={(e) => setForm((prev) => ({ ...prev, textLine1: e.target.value }))}
                placeholder={zebraBusinessCardMode ? 'Charlie' : 'Optional'}
              />
              {zebraBusinessCardMode ? (
                <p className="mt-2 text-xs text-[#2d2d2d]">This is the person or business name printed beside the QR.</p>
              ) : null}
            </div>

            <div>
              <label className={LABEL_CLASS}>Copies</label>
              <input
                className={INPUT_CLASS}
                type="number"
                min="1"
                max="250"
                value={form.copies}
                onChange={(e) => setForm((prev) => ({ ...prev, copies: e.target.value }))}
              />
            </div>

            <div className="flex items-end gap-3 md:justify-end">
              <button className={BUTTON_CLASS} type="button" onClick={runTestPrint} disabled={printing}>
                Test
              </button>
              <button className={BUTTON_CLASS} type="submit" disabled={printing}>
                Print
              </button>
            </div>
          </form>

          <div className="retro-window mt-8 p-5 sm:p-6">
            <h3 className="flex items-center gap-3 font-display text-4xl leading-none text-[#1b1b1b]">
              <SpriteIcon frame={statusFrame} label="Current status icon" />
              Status
            </h3>
            <p className="mt-3 text-base text-[#222]">{message}</p>
            <div className="mt-4 grid gap-2 font-mono text-xs text-[#2b2b2b] sm:grid-cols-2">
              <span>Job ID: {job?.jobId || '-'}</span>
              <span>State: {statusText || '-'}</span>
              <span>Created: {job?.createdAt || '-'}</span>
              <span>Error: {job?.error || '-'}</span>
            </div>
          </div>
        </>
      ) : (
        <div className="retro-window mt-8 p-5 sm:p-6">
          <h3 className="font-display text-4xl leading-none text-[#1b1b1b]">
            {printer?.batch?.title || 'Batch Print'}
          </h3>
          <p className="mt-3 text-sm text-[#222]">
            {printer?.batch?.helperText || 'Paste barcode data below to print a batch.'}
          </p>
        </div>
      )}

      <form className="mt-8 grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(300px,0.95fr)]" onSubmit={submitBatch}>
        <div className="retro-window p-5 sm:p-6">
          <div className="flex flex-wrap items-center gap-3">
            <h3 className="font-display text-4xl leading-none text-[#1b1b1b]">
              {printer?.batch?.title || 'Batch Print'}
            </h3>
            {printer.printerId === 'zebra-zp505' ? (
              <span className="border-2 border-[#1f4688] bg-[#dce8ff] px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-[#1a3563]">
                Zebra Lane
              </span>
            ) : null}
          </div>

          <p className="mt-3 text-sm text-[#2a2a2a]">
            {printer?.batch?.helperText || 'One field only: paste CSV or newline barcode data. Batch type cannot be mixed.'}
          </p>

          <div className="mt-5 grid gap-6 md:grid-cols-2">
            <div>
              <label className={LABEL_CLASS}>Batch Label Type</label>
              <select
                className={INPUT_CLASS}
                value={form.labelType}
                onChange={(e) => setForm((prev) => ({ ...prev, labelType: e.target.value }))}
              >
                {printer.labelTypes.map((item) => (
                  <option key={item.id} value={item.id}>{item.label} ({item.id})</option>
                ))}
              </select>
            </div>

            <div>
              <label className={LABEL_CLASS}>Batch Barcode Type</label>
              <select
                className={INPUT_CLASS}
                value={batchType}
                onChange={(e) => setBatchType(e.target.value)}
              >
                {batchTypes.map((type) => (
                  <option key={type} value={type}>{type}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="mt-5">
            <p className={LABEL_CLASS}>Quick Picks</p>
            <div className="mt-2 flex flex-wrap gap-2">
              {batchTypes.map((type) => (
                <button
                  key={type}
                  className={SUBTLE_BUTTON_CLASS}
                  type="button"
                  onClick={() => setBatchType(type)}
                  disabled={batchPrinting || batchType === type}
                >
                  {type === 'UPCA' ? 'UPC-A' : type}
                </button>
              ))}
              <button
                className={SUBTLE_BUTTON_CLASS}
                type="button"
                onClick={() => setBatchInput('036000291452\n012345678905\n051000012517')}
                disabled={batchPrinting}
              >
                Load Sample
              </button>
              <button
                className={SUBTLE_BUTTON_CLASS}
                type="button"
                onClick={() => setBatchInput('')}
                disabled={batchPrinting || batchInput.trim() === ''}
              >
                Clear
              </button>
            </div>
          </div>

          <div className="mt-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <label className={`${LABEL_CLASS} flex items-center gap-2`}>
                <SpriteIcon frame={SPRITE_FRAME.scanner} label="Barcode scanner" className="scale-90" />
                Batch Barcode Data
              </label>
              <span className="text-[11px] font-mono uppercase tracking-[0.18em] text-[#21466e]">
                {batchAnalysis.count}/{batchMaxValues} values
              </span>
            </div>
            <textarea
              className={`${INPUT_CLASS} min-h-56`}
              value={batchInput}
              onChange={(e) => setBatchInput(e.target.value)}
              placeholder={'036000291452\n036000291469\nor comma separated values'}
            />
            <div className="mt-3 flex flex-wrap gap-3 text-xs text-[#2b2b2b]">
              <span>{printer?.batch?.inputHint || 'Newlines and commas are both accepted.'}</span>
              {batchType === 'UPCA' ? (
                <span>{printer?.batch?.upcaHint || 'UPC-A accepts 11 or 12 digits.'}</span>
              ) : null}
            </div>
          </div>

          <div className="mt-5 flex flex-wrap items-center gap-3">
            <button
              className={BUTTON_CLASS}
              type="submit"
              disabled={batchPrinting || !batchReady}
            >
              {batchActionLabel(printer, batchType)}
            </button>
            <span className="text-sm text-[#2b2b2b]">
              {batchAnalysis.chunkCount > 0
                ? `${batchAnalysis.chunkCount} batch ${batchAnalysis.chunkCount === 1 ? 'job' : 'jobs'} at ${batchChunkSize} value${batchChunkSize === 1 ? '' : 's'} each`
                : 'Paste values to calculate batch jobs'}
            </span>
          </div>

          <div className="mt-4 grid gap-2 font-mono text-xs text-[#2b2b2b] sm:grid-cols-2">
            <span>{batchMessage}</span>
            <span>Batch ID: {batchSummary?.batchId ?? '-'}</span>
            <span>Saved Count: {batchSummary?.count ?? '-'}</span>
            <span>Sent/Error: {(batchSummary?.sentCount ?? '-')}/{(batchSummary?.errorCount ?? '-')}</span>
          </div>
        </div>

        <aside className="retro-window p-5 sm:p-6">
          <h3 className="font-display text-4xl leading-none text-[#1b1b1b]">Batch Summary</h3>

          <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
            <div className="border-2 border-[#a1a1a1] bg-[#ececec] p-4">
              <p className={LABEL_CLASS}>Parsed Values</p>
              <p className="mt-2 text-3xl leading-none text-[#1a1a1a]">{batchAnalysis.count}</p>
            </div>
            <div className="border-2 border-[#a1a1a1] bg-[#ececec] p-4">
              <p className={LABEL_CLASS}>Values Per Batch</p>
              <p className="mt-2 text-3xl leading-none text-[#1a1a1a]">{batchChunkSize}</p>
            </div>
            <div className="border-2 border-[#a1a1a1] bg-[#ececec] p-4">
              <p className={LABEL_CLASS}>Batch Jobs</p>
              <p className="mt-2 text-3xl leading-none text-[#1a1a1a]">{batchAnalysis.chunkCount}</p>
              <p className="mt-2 text-xs text-[#3a3a3a]">
                {batchAnalysis.chunkCount > 0
                  ? `Last job carries ${batchAnalysis.remainder} value${batchAnalysis.remainder === 1 ? '' : 's'}.`
                  : 'No batch jobs queued yet.'}
              </p>
            </div>
            <div className="border-2 border-[#a1a1a1] bg-[#ececec] p-4">
              <p className={LABEL_CLASS}>Duplicates</p>
              <p className="mt-2 text-3xl leading-none text-[#1a1a1a]">{batchAnalysis.duplicates.length}</p>
            </div>
          </div>

          <div className="mt-5 border-2 border-[#9ba9b6] bg-[#e6eef7] p-4">
            <p className={LABEL_CLASS}>Readiness</p>
            <p className="mt-2 text-sm text-[#1e2932]">{batchReadinessMessage}</p>
          </div>

          {batchAnalysis.invalidValues.length > 0 ? (
            <div className="mt-5 border-2 border-[#b37e70] bg-[#f6dfd7] p-4">
              <p className={LABEL_CLASS}>Invalid Values</p>
              <p className="mt-2 text-sm text-[#3b1f18]">
                {batchAnalysis.invalidValues.slice(0, 6).join(', ')}
                {batchAnalysis.invalidValues.length > 6 ? ' ...' : ''}
              </p>
            </div>
          ) : null}

          {batchAnalysis.duplicates.length > 0 ? (
            <div className="mt-5 border-2 border-[#b59b68] bg-[#f6ebcf] p-4">
              <p className={LABEL_CLASS}>Duplicate Values</p>
              <p className="mt-2 text-sm text-[#3c2f12]">
                {batchAnalysis.duplicates.slice(0, 5).map((item) => `${item.value} x${item.count}`).join(', ')}
                {batchAnalysis.duplicates.length > 5 ? ' ...' : ''}
              </p>
            </div>
          ) : null}

          {batchAnalysis.previewValues.length > 0 ? (
            <div className="mt-5 border-2 border-[#8fa2b8] bg-[#edf2f7] p-4">
              <p className={LABEL_CLASS}>First Labels</p>
              <ol className="mt-3 space-y-2 font-mono text-sm text-[#22313f]">
                {batchAnalysis.previewValues.map((value, index) => (
                  <li key={`${value}-${index}`}>{String(index + 1).padStart(2, '0')}. {value}</li>
                ))}
              </ol>
              {batchAnalysis.hiddenPreviewCount > 0 ? (
                <p className="mt-3 text-xs text-[#314454]">
                  {batchAnalysis.hiddenPreviewCount} more value{batchAnalysis.hiddenPreviewCount === 1 ? '' : 's'} not shown.
                </p>
              ) : null}
            </div>
          ) : null}
        </aside>
      </form>
    </section>
  );
}

export default function App() {
  const [route, setRoute] = useState(detectRoute());
  const [config, setConfig] = useState({ printers: [], brotherMode: 'template' });
  const [health, setHealth] = useState(null);
  const [cupsQueues, setCupsQueues] = useState(new Set());

  useEffect(() => {
    const onPopState = () => setRoute(detectRoute());
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, []);

  useEffect(() => {
    (async () => {
      try {
        const [printerConfig, h] = await Promise.all([
          api('/api/print/config'),
          api('/api/health'),
        ]);
        setConfig(printerConfig);
        setHealth(h);
        try {
          const cups = await api('/api/printers');
          setCupsQueues(normalizeCupsQueues(cups));
        } catch {
          setCupsQueues(new Set());
        }
      } catch {
        setConfig({ printers: [], brotherMode: 'template' });
        setHealth(null);
        setCupsQueues(new Set());
      }
    })();
  }, []);

  const printerById = useMemo(() => {
    const map = new Map();
    for (const printer of config.printers || []) {
      map.set(printer.printerId, printer);
    }
    return map;
  }, [config]);

  let page = null;
  if (route === '/printers') {
    page = (
      <PrinterList
        printers={config.printers || []}
        cupsQueues={cupsQueues}
        onOpen={(next) => goRoute(next, setRoute)}
      />
    );
  } else if (route.startsWith('/printers/')) {
    const printerId = route.replace('/printers/', '').trim();
    const printer = printerById.get(printerId);
    if (printer) {
      page = (
        <PrinterPage
          printer={printer}
          cupsQueues={cupsQueues}
          onBack={() => goRoute('/printers', setRoute)}
        />
      );
    } else {
      page = (
        <section className={PANEL_CLASS}>
          <h2 className="font-display text-4xl leading-none text-[#1b1b1b]">Unknown printer route</h2>
          <button className={`${SUBTLE_BUTTON_CLASS} mt-4`} onClick={() => goRoute('/printers', setRoute)}>
            Back to printers
          </button>
        </section>
      );
    }
  }

  return (
    <div className="relative min-h-screen overflow-hidden text-[#111]">
      <div className="pointer-events-none absolute inset-0">
        <div className="absolute inset-x-0 top-0 h-28 bg-gradient-to-b from-white/20 to-transparent" />
      </div>

      <div className="relative mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
        <header className="retro-titlebar mb-6 p-4 sm:p-5">
          <div className="flex flex-wrap items-center gap-4">
            <div className="h-3.5 w-3.5 rounded-full border border-[#183f18] bg-[#79ed79] shadow-[0_0_8px_rgba(121,237,121,0.8)]" />
            <strong className="font-display text-5xl leading-none">Printer Hub</strong>
            <span className="ml-auto text-sm font-mono">Brother Mode: {config.brotherMode || 'template'}</span>
          </div>
        </header>

        {page}

        <footer className="retro-window mt-6 p-5">
          <div className="grid gap-3 font-mono text-xs text-[#222] sm:grid-cols-3">
            <span><strong>CUPS:</strong> {health?.cups || 'unknown'}</span>
            <span><strong>DB:</strong> {health?.database || 'unknown'}</span>
            <span><strong>TZ:</strong> {health?.timezone || 'unknown'}</span>
          </div>
        </footer>
      </div>
    </div>
  );
}
