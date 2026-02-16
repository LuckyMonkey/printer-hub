import React, { useEffect, useMemo, useState } from 'react';

const PRINTERS = {
  brother: {
    key: 'brother',
    title: 'Brother QL-820NWB',
    subtitle: 'Single 2.4 x 1.1 barcode label',
    expectedCount: 1,
    endpoint: '/api/print/brother',
    path: '/printers/brother',
    defaultTitle: 'brother-single-barcode',
  },
  zebra: {
    key: 'zebra',
    title: 'Zebra ZP505',
    subtitle: '4x6 ZPL layout with 12 barcodes',
    expectedCount: 12,
    endpoint: '/api/print/zebra',
    path: '/printers/zebra',
    defaultTitle: 'zebra-4x6-12up',
  },
  hp: {
    key: 'hp',
    title: 'HP Envy 5055',
    subtitle: '3x10 sheet with 30 barcodes',
    expectedCount: 30,
    endpoint: '/api/print/hp',
    path: '/printers/hp',
    defaultTitle: 'hp-avery-3x10-sheet',
  },
};

const SYMBOLOGY_OPTIONS = ['code128', 'qr', 'upc'];

function parseValues(text) {
  return (text || '')
    .split(/[\r\n,]+/)
    .map((part) => part.trim())
    .filter(Boolean);
}

function detectRoute() {
  const pathname = window.location.pathname || '/ui/';
  const withoutBase = pathname.replace(/^\/ui/, '') || '/';
  const normalized = withoutBase.endsWith('/') && withoutBase !== '/'
    ? withoutBase.slice(0, -1)
    : withoutBase;

  if (normalized === '/printers/brother') return '/printers/brother';
  if (normalized === '/printers/zebra') return '/printers/zebra';
  if (normalized === '/printers/hp') return '/printers/hp';
  return '/';
}

function goRoute(route, setRoute) {
  const path = route === '/' ? '/ui/' : `/ui${route}`;
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

function Home({ config, onOpen }) {
  return (
    <section className="window home-window">
      <h2>Pick Printer</h2>
      <div className="home-buttons">
        {Object.values(PRINTERS).map((printer) => (
          <button key={printer.key} className="home-card" onClick={() => onOpen(printer.path)}>
            <span className="home-card-title">{printer.title}</span>
            <span className="home-card-subtitle">{printer.subtitle}</span>
            <code>{config?.queues?.[printer.key] || `queue:${printer.key}`}</code>
          </button>
        ))}
      </div>
      <p className="hint-text">
        Input parser accepts newlines, CR, or commas and normalizes to CSV for PostGIS storage.
      </p>
    </section>
  );
}

function PrinterPage({ printer, config, onBack }) {
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('Ready.');
  const [batches, setBatches] = useState([]);
  const [form, setForm] = useState({
    symbology: 'code128',
    zebraMode: 'auto',
    title: printer.defaultTitle,
    copies: 1,
    input: '',
  });

  const values = useMemo(() => parseValues(form.input), [form.input]);
  const remaining = printer.expectedCount - values.length;

  async function loadBatches() {
    try {
      const data = await api(`/api/batches?printer=${printer.key}&limit=20`);
      setBatches(data.batches || []);
    } catch (err) {
      setMessage(err.message);
    }
  }

  useEffect(() => {
    loadBatches();
    setForm({
      symbology: 'code128',
      zebraMode: 'auto',
      title: printer.defaultTitle,
      copies: 1,
      input: '',
    });
    setMessage('Ready.');
  }, [printer.key]);

  async function submit(event) {
    event.preventDefault();

    if (values.length !== printer.expectedCount) {
      setMessage(`Need exactly ${printer.expectedCount} barcodes. Current: ${values.length}`);
      return;
    }

    setLoading(true);
    setMessage('Submitting print job...');

    try {
      const payload = {
        symbology: form.symbology,
        title: form.title,
        copies: Number(form.copies),
        input: form.input,
      };
      if (printer.key === 'zebra') {
        payload.zebraMode = form.zebraMode;
      }

      const result = await api(printer.endpoint, {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      setMessage(`Queued: ${result.jobOutput || 'ok'} | batch #${result.batchId}`);
      await loadBatches();
    } catch (err) {
      setMessage(err.message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <section className="window printer-window">
      <div className="page-head">
        <button onClick={onBack}>Home</button>
        <h2>{printer.title}</h2>
        <span className="queue-pill">{config?.queues?.[printer.key] || 'queue unset'}</span>
      </div>

      <p className="subline">{printer.subtitle}</p>

      <form className="form-layout" onSubmit={submit}>
        <label>Symbology</label>
        <select
          value={form.symbology}
          onChange={(e) => setForm((prev) => ({ ...prev, symbology: e.target.value }))}
        >
          {SYMBOLOGY_OPTIONS.map((option) => (
            <option key={option} value={option}>{option.toUpperCase()}</option>
          ))}
        </select>

        {printer.key === 'zebra' && (
          <>
            <label>Zebra Render</label>
            <select
              value={form.zebraMode}
              onChange={(e) => setForm((prev) => ({ ...prev, zebraMode: e.target.value }))}
            >
              {(config?.zebraRenderModes || ['auto', 'z64', 'native']).map((mode) => (
                <option key={mode} value={mode}>{mode.toUpperCase()}</option>
              ))}
            </select>
          </>
        )}

        <label>Job Title</label>
        <input
          value={form.title}
          onChange={(e) => setForm((prev) => ({ ...prev, title: e.target.value }))}
        />

        <label>Copies</label>
        <input
          type="number"
          min="1"
          max="250"
          value={form.copies}
          onChange={(e) => setForm((prev) => ({ ...prev, copies: e.target.value }))}
        />

        <label>Barcode Input</label>
        <textarea
          rows={9}
          placeholder="Paste barcodes separated by newline, CR, or comma"
          value={form.input}
          onChange={(e) => setForm((prev) => ({ ...prev, input: e.target.value }))}
        />

        <div className="status-row">
          <strong>Parsed: {values.length}</strong>
          <span>Required: {printer.expectedCount}</span>
          <span>{remaining > 0 ? `Missing: ${remaining}` : remaining < 0 ? `Over by: ${Math.abs(remaining)}` : 'Ready to print'}</span>
        </div>

        <button type="submit" disabled={loading}>Print {printer.expectedCount}</button>
      </form>

      <div className="hint-box">
        CR/newline/comma is normalized to CSV for DB save, and restored as multiline in recent history.
      </div>

      <div className="history">
        <h3>Recent Batches</h3>
        <ul>
          {batches.length === 0 && <li>No saved batches yet.</li>}
          {batches.map((batch) => (
            <li key={batch.id}>
              <strong>Batch #{batch.id}</strong>
              <span>{batch.createdAt}</span>
              <span>{batch.symbology.toUpperCase()} | {batch.count} values | Sheets: {batch.sheetsBackup}</span>
              <code>{batch.csv}</code>
              <button
                type="button"
                onClick={() => setForm((prev) => ({ ...prev, input: batch.restoredMultiline }))}
              >
                Load Into Form
              </button>
            </li>
          ))}
        </ul>
      </div>

      <div className="message-line">{message}</div>
    </section>
  );
}

export default function App() {
  const [route, setRoute] = useState(detectRoute());
  const [config, setConfig] = useState(null);
  const [health, setHealth] = useState(null);

  useEffect(() => {
    const onPopState = () => setRoute(detectRoute());
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, []);

  useEffect(() => {
    (async () => {
      try {
        const [cfg, h] = await Promise.all([
          api('/api/config'),
          api('/api/health'),
        ]);
        setConfig(cfg);
        setHealth(h);
      } catch {
        setConfig(null);
        setHealth(null);
      }
    })();
  }, []);

  let page = null;
  if (route === '/') {
    page = <Home config={config} onOpen={(next) => goRoute(next, setRoute)} />;
  } else if (route === '/printers/brother') {
    page = <PrinterPage printer={PRINTERS.brother} config={config} onBack={() => goRoute('/', setRoute)} />;
  } else if (route === '/printers/zebra') {
    page = <PrinterPage printer={PRINTERS.zebra} config={config} onBack={() => goRoute('/', setRoute)} />;
  } else if (route === '/printers/hp') {
    page = <PrinterPage printer={PRINTERS.hp} config={config} onBack={() => goRoute('/', setRoute)} />;
  }

  return (
    <div className="desktop-shell">
      <header className="titlebar">
        <div className="led" />
        <strong>Printer Hub OS9</strong>
        <span className="meta">Brother + Zebra + HP</span>
      </header>

      {page}

      <footer className="footerbar">
        <span>CUPS: {health?.cups || 'unknown'}</span>
        <span>DB: {health?.database || 'unknown'}</span>
        <span>TZ: {health?.timezone || 'unknown'}</span>
      </footer>
    </div>
  );
}
