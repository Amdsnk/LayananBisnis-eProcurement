import React, { createContext, useContext, useState, useEffect } from "react";
import { createRoot } from "react-dom/client";
import {
  BrowserRouter,
  Routes,
  Route,
  Link,
  NavLink,
  useNavigate,
  useParams,
  useSearchParams,
} from "react-router-dom";
import {
  QueryClient,
  QueryClientProvider,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import {
  LayoutDashboard,
  FileText,
  Users,
  Wallet,
  ShieldCheck,
  ArrowUpRight,
  ChevronRight,
  Plus,
  Search,
  LogOut,
  Menu,
  X,
  Check,
  Clock,
  Package,
  Receipt,
  Network,
  Settings,
  Download,
  LockKeyhole,
  Building2,
  Trash2,
  RefreshCw,
  Globe,
  CheckCircle2,
  AlertTriangle,
  Send,
  Layers,
} from "lucide-react";
import { api, ApiError, clearToken, upload } from "./api";
import type {
  Doc,
  Kind,
  Line,
  Me,
  Lookups,
  Lookup,
  Page,
  Detail,
  Dashboard,
  Bid,
  BusinessData,
} from "./types";
import "./style.css";
const client = new QueryClient({
  defaultOptions: {
    queries: { retry: false, staleTime: 15000, refetchOnWindowFocus: true },
  },
});
const Context = createContext<{
  me: Me;
  lang: "id" | "en";
  t: (id: string, en: string) => string;
}>({} as never);
const labels: Record<Kind, [string, string]> = {
  pr: ["Permintaan pembelian", "Purchase requisitions"],
  tender: ["Sourcing & tender", "Sourcing & tenders"],
  contract: ["Kontrak", "Contracts"],
  po: ["Purchase order", "Purchase orders"],
  receipt: ["Penerimaan", "Goods & service receipt"],
  return: ["Retur penerimaan", "Receipt returns"],
  invoice: ["Invoice & matching", "Invoices & matching"],
  credit: ["Credit note", "Credit notes"],
};
const money = (v: string | number, c = "IDR") =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: c,
    maximumFractionDigits: c === "IDR" ? 0 : 2,
  }).format(Number(v));
const date = (v: string | null | undefined) =>
  v
    ? new Intl.DateTimeFormat("id-ID", {
        dateStyle: "medium",
        timeZone: "Asia/Jakarta",
      }).format(new Date(v))
    : "—";
function Status({ value }: { value: string }) {
  return (
    <span className={"status status-" + value}>
      <span />
      {value.replaceAll("_", " ")}
    </span>
  );
}
function ErrorBox({ error }: { error: unknown }) {
  if (!error) return null;
  const e = error as ApiError;
  return (
    <div className="error" role="alert">
      <AlertTriangle size={18} />
      <div>
        {e.message ?? String(error)}
        {e.fields &&
          Object.entries(e.fields).map(([k, v]) => (
            <div key={k}>
              {k}: {v.join(", ")}
            </div>
          ))}
      </div>
    </div>
  );
}
function Loading() {
  return (
    <div className="loading" role="status">
      <RefreshCw className="spin" /> Memuat workspace…
    </div>
  );
}
function Empty({
  text = "Belum ada data. Buat dokumen untuk memulai.",
}: {
  text?: string;
}) {
  return (
    <div className="empty">
      <Layers size={34} />
      <h3>{text}</h3>
      <p>Data tersimpan akan muncul di sini sesuai akses Anda.</p>
    </div>
  );
}
function Field({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <label className="field">
      <span>{label}</span>
      {children}
    </label>
  );
}
function Heading({
  eyebrow,
  title,
  description,
  children,
}: {
  eyebrow: string;
  title: string;
  description?: string;
  children?: React.ReactNode;
}) {
  return (
    <div className="heading">
      <div>
        <div className="eyebrow">{eyebrow}</div>
        <h1>{title}</h1>
        {description && <p>{description}</p>}
      </div>
      <div className="heading-actions">{children}</div>
    </div>
  );
}
function Modal({
  title,
  onClose,
  children,
}: {
  title: string;
  onClose: () => void;
  children: React.ReactNode;
}) {
  const ref = React.useRef<HTMLDivElement>(null);
  useEffect(() => {
    const prev = document.activeElement as HTMLElement;
    ref.current?.focus();
    const listener = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
      if (e.key === "Tab") {
        const a = ref.current?.querySelectorAll<HTMLElement>(
          "button,input,select,textarea,a[href]",
        );
        if (!a?.length) return;
        const first = a[0],
          last = a[a.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };
    document.addEventListener("keydown", listener);
    return () => {
      document.removeEventListener("keydown", listener);
      prev?.focus();
    };
  }, [onClose]);
  return (
    <div className="modal-backdrop">
      <div
        ref={ref}
        tabIndex={-1}
        className="modal"
        role="dialog"
        aria-modal="true"
        aria-label={title}
      >
        <div className="modal-header">
          <h2>{title}</h2>
          <button
            className="iconbtn"
            aria-label="Tutup dialog"
            onClick={onClose}
          >
            <X />
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
function Login({ onDone }: { onDone: () => void }) {
  const authConfig = useQuery({
    queryKey: ["auth-config"],
    queryFn: () => api<{ oidc_enabled: boolean }>("auth-config"),
  });
  const [email, setEmail] = useState(""),
    [password, setPassword] = useState(""),
    [error, setError] = useState<unknown>(),
    [busy, setBusy] = useState(false);
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(undefined);
    try {
      await api("login", "POST", { email, password });
      clearToken();
      onDone();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="login">
      <div className="login-art">
        <div className="brand">
          <span className="brand-icon">
            <Layers />
          </span>
          LayananBisnis<span className="brand-dot">®</span>
        </div>
        <div className="login-copy">
          <div className="eyebrow">THE PROCUREMENT WORKSPACE</div>
          <h1>
            Better decisions.
            <br />
            Every purchase.
          </h1>
          <p>
            Satu workspace untuk kebutuhan, pemasok, persetujuan, dan seluruh
            perjalanan pengadaan.
          </p>
          <div className="login-orbit">
            <div className="orbit-label">
              <ShieldCheck /> Controlled approvals
            </div>
            <div className="orbit-label">
              <LockKeyhole /> Sealed submissions
            </div>
            <div className="orbit-label">
              <Network /> Connected operations
            </div>
          </div>
        </div>
        <small>Procurement, with accountability built in.</small>
      </div>
      <div className="login-form">
        <div className="eyebrow">SECURE ACCESS</div>
        <h2>Selamat datang kembali.</h2>
        <p>Masuk ke workspace organisasi Anda.</p>
        <form onSubmit={submit}>
              <Field label="Email">
                <input
                  type="email"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  autoComplete="username"
                  placeholder="nama@perusahaan.com"
                />
              </Field>
              <Field label="Password">
                <input
                  type="password"
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="current-password"
                />
              </Field>
          <ErrorBox error={error} />
          <button className="primary full" disabled={busy}>
            {busy ? "Memproses…" : "Masuk ke workspace"}
            <ArrowUpRight size={18} />
          </button>
        </form>
        {authConfig.data?.oidc_enabled && (
          <a className="secondary full" href="/api/v1/oidc/start">
            Sign in with organization SSO
          </a>
        )}
        <div className="login-foot">
          <LockKeyhole size={14} /> Akses mengikuti keanggotaan dan peran
          organisasi.
        </div>
      </div>
    </div>
  );
}
function App() {
  const q = useQuery({ queryKey: ["me"], queryFn: () => api<Me>("me") });
  const [lang, setLang] = useState<"id" | "en">(() =>
      localStorage.getItem("language") === "en" ? "en" : "id",
    ),
    [open, setOpen] = useState(false);
  const nav = useNavigate();
  const t = (a: string, b: string) => (lang === "id" ? a : b);
  if (q.isLoading) return <Loading />;
  if (!q.data)
    return (
      <Login onDone={() => client.invalidateQueries({ queryKey: ["me"] })} />
    );
  const me = q.data;
  const admin = me.permissions.includes("master");
  const vendor = me.membership.role.startsWith("vendor");
  return (
    <Context.Provider value={{ me, lang, t }}>
      <div className="shell">
        <a className="skip" href="#content">
          Lewati navigasi
        </a>
        <aside className={open ? "sidebar visible" : "sidebar"}>
          <Link to="/" className="brand">
            <span className="brand-icon">
              <Layers size={21} />
            </span>
            <span>
              LayananBisnis<small>PROCUREMENT WORKSPACE</small>
            </span>
          </Link>
          <div className="org">
            <Building2 size={17} />
            <div>
              {me.tenant.name}
              <small>
                {me.production ? "Production" : "Sandbox / local environment"}
              </small>
            </div>
          </div>
          <nav onClick={() => setOpen(false)}>
            <div className="nav-label">WORKSPACE</div>
            <NavLink end to="/">
              <LayoutDashboard size={18} />
              {t("Ringkasan", "Overview")}
            </NavLink>
            {(Object.keys(labels) as Kind[])
              .filter(
                (k) =>
                  !vendor ||
                  ["tender", "po", "contract", "invoice", "credit"].includes(k),
              )
              .map((k) => (
                <NavLink key={k} to={"/documents/" + k}>
                  {k === "receipt" ? (
                    <Package size={18} />
                  ) : k === "invoice" ? (
                    <Receipt size={18} />
                  ) : (
                    <FileText size={18} />
                  )}
                  <span>{t(...labels[k])}</span>
                </NavLink>
              ))}
            {!vendor && (
              <>
                <div className="nav-label">CONTROL CENTER</div>
                {(me.permissions.includes("vendor.manage") ||
                  me.membership.role === "auditor") && (
                  <NavLink to="/admin/vendors">
                    <Users size={18} />
                    {t("Pemasok", "Suppliers")}
                  </NavLink>
                )}
                {(me.permissions.includes("budget.manage") ||
                  me.permissions.includes("budget.read") ||
                  me.membership.role === "auditor") && (
                  <NavLink to="/admin/budgets">
                    <Wallet size={18} />
                    {t("Anggaran", "Budgets")}
                  </NavLink>
                )}
                {admin && (
                  <NavLink to="/admin/legal_entities">
                    <Building2 size={18} />
                    {t("Master data", "Master data")}
                  </NavLink>
                )}
                {me.permissions.includes("users") && (
                  <NavLink to="/admin/users">
                    <Users size={18} />
                    {t("Pengguna", "Users")}
                  </NavLink>
                )}
                {me.permissions.includes("policy") && (
                  <NavLink to="/admin/policies">
                    <ShieldCheck size={18} />
                    {t("Aturan persetujuan", "Approval policies")}
                  </NavLink>
                )}
                {me.permissions.includes("integration.manage") && (
                  <NavLink to="/admin/integrations">
                    <Network size={18} />
                    {t("Integrasi", "Integrations")}
                  </NavLink>
                )}
                {me.permissions.includes("audit.read") && (
                  <NavLink to="/admin/audit">
                    <ShieldCheck size={18} />
                    Audit trail
                  </NavLink>
                )}
              </>
            )}
            <NavLink to="/data-tools">
              <Download size={18} />
              {t("Impor & ekspor", "Import & export")}
            </NavLink>
            <NavLink to="/security">
              <LockKeyhole size={18} />
              {t("Keamanan akun", "Account security")}
            </NavLink>
          </nav>
          <div className="sidebar-bottom">
            <div className="avatar">
              {me.user.name.slice(0, 2).toUpperCase()}
            </div>
            <div>
              {me.user.name}
              <small>{me.membership.role.replaceAll("_", " ")}</small>
            </div>
            <button
              className="iconbtn"
              aria-label="Keluar"
              onClick={async () => {
                await api("logout", "POST");
                clearToken();
                client.clear();
                nav("/");
              }}
            >
              <LogOut size={17} />
            </button>
          </div>
        </aside>
        <div className="main">
          <header>
            <button
              className="iconbtn mobile"
              onClick={() => setOpen(!open)}
              aria-label="Buka menu"
            >
              <Menu />
            </button>
            <div className="header-caption">
              WORKSPACE <ChevronRight size={13} />{" "}
              {vendor ? "Supplier portal" : "Procurement operations"}
            </div>
            <div className="header-right">
              <span className="environment">
                <span />
                {me.production ? "Live environment" : "Sandbox"}
              </span>
              {me.memberships.length > 1 && (
                <select
                  aria-label="Organisasi"
                  value={me.membership.tenant_id}
                  onChange={async (e) => {
                    await api("tenant", "POST", {
                      tenant_id: Number(e.target.value),
                    });
                    clearToken();
                    client.clear();
                    location.assign("/");
                  }}
                >
                  {me.memberships.map((m) => (
                    <option key={m.tenant_id} value={m.tenant_id}>
                      {m.name}
                    </option>
                  ))}
                </select>
              )}
              <button
                className="lang"
                onClick={() => {
                  const v = lang === "id" ? "en" : "id";
                  setLang(v);
                  localStorage.setItem("language", v);
                  document.documentElement.lang = v;
                }}
              >
                <Globe size={15} />
                {lang.toUpperCase()}
              </button>
            </div>
          </header>
          <main id="content">
            <Routes>
              <Route path="/" element={<Overview />} />
              <Route path="/documents/:kind" element={<Documents />} />
              <Route path="/document/new/:kind" element={<Editor />} />
              <Route path="/document/:id/edit" element={<Editor />} />
              <Route path="/document/:id" element={<DocumentView />} />
              <Route path="/admin/:table" element={<Admin />} />
              <Route path="/security" element={<Security />} />
              <Route path="/data-tools" element={<DataTools />} />
              <Route
                path="*"
                element={<Empty text="Halaman tidak ditemukan" />}
              />
            </Routes>
          </main>
          <footer>
            LayananBisnis{" "}
            <span>Controlled procurement. Clear accountability.</span>
          </footer>
        </div>
      </div>
    </Context.Provider>
  );
}
function Overview() {
  const { me, t } = useContext(Context);
  const q = useQuery({
    queryKey: ["dashboard"],
    queryFn: () => api<Dashboard>("dashboard"),
  });
  if (q.isLoading) return <Loading />;
  if (!q.data) return <ErrorBox error={q.error} />;
  const d = q.data;
  const count = (kind: Kind) =>
    d.counts
      .filter((x) => x.kind === kind)
      .reduce((a, x) => a + Number(x.count), 0);
  return (
    <>
      <Heading
        eyebrow={t("OPERATIONS / RINGKASAN", "OPERATIONS / OVERVIEW")}
        title={t(
          "Setiap pengadaan, terkendali.",
          "Every purchase, under control.",
        )}
        description={t(
          "Lihat pekerjaan yang membutuhkan keputusan Anda hari ini.",
          "A clear view of the work that needs your decision.",
        )}
      >
        <span className="date-chip">
          <Clock size={15} />
          {date(new Date().toISOString())} · WIB
        </span>
      </Heading>
      <div className="overview-hero">
        <div>
          <div className="eyebrow">YOUR PROCUREMENT PULSE</div>
          <h2>
            {t("Dari kebutuhan ke keputusan.", "From request to decision.")}
          </h2>
          <p>
            {t(
              "Dokumen, anggaran, dan persetujuan terhubung dalam satu jejak kerja.",
              "Documents, budgets, and approvals in one connected workflow.",
            )}
          </p>
          <Link
            className="hero-link"
            to={
              "/documents/" +
              (me.membership.role.startsWith("vendor") ? "tender" : "pr")
            }
          >
            {t("Buka workbench", "Open workbench")}
            <ArrowUpRight size={17} />
          </Link>
        </div>
        <div className="hero-schematic">
          <div className="node">
            <FileText size={20} />
            <span>REQUEST</span>
          </div>
          <div className="node">
            <ShieldCheck size={20} />
            <span>APPROVE</span>
          </div>
          <div className="node node-accent">
            <Package size={20} />
            <span>DELIVER</span>
          </div>
          <div className="schematic-line" />
        </div>
      </div>
      <div className="metrics">
        {[
          [t("Permintaan", "Requisitions"), count("pr"), FileText],
          [t("Tender", "Sourcing events"), count("tender"), Users],
          [t("Purchase order", "Purchase orders"), count("po"), Package],
          [t("Menunggu Anda", "Your approvals"), d.pending.length, ShieldCheck],
        ].map(([name, value, Icon], i) => {
          const I = Icon as typeof FileText;
          return (
            <div className="metric" key={i}>
              <div>
                <span>{String(name)}</span>
                <I size={18} />
              </div>
              <strong>{String(value).padStart(2, "0")}</strong>
              <small>
                {i === 3
                  ? t("Tugas persetujuan ditugaskan", "Assigned approval tasks")
                  : t(
                      "Dokumen sesuai akses Anda",
                      "Documents within your access",
                    )}
              </small>
            </div>
          );
        })}
      </div>
      <div className="overview-grid">
        <section className="panel">
          <div className="panel-head">
            <h2>{t("Dokumen terbaru", "Recent documents")}</h2>
            <Link to="/documents/pr">
              {t("Lihat semua", "View all")}
              <ArrowUpRight size={16} />
            </Link>
          </div>
          {d.recent.length ? (
            d.recent.map((x) => (
              <Link className="recent" key={x.id} to={"/document/" + x.id}>
                <span className="document-icon">
                  <FileText size={19} />
                </span>
                <div>
                  <b>{x.title}</b>
                  <small>
                    {x.number} · {date(x.created_at)}
                  </small>
                </div>
                <Status value={x.status} />
                <ChevronRight size={17} />
              </Link>
            ))
          ) : (
            <Empty />
          )}
        </section>
        <section className="panel attention">
          <div className="panel-head">
            <h2>{t("Perlu keputusan", "Needs your decision")}</h2>
            <span className="count">{d.pending.length}</span>
          </div>
          {d.pending.length ? (
            d.pending.map((x) => (
              <Link className="task" to={"/document/" + x.id} key={x.id}>
                <div>
                  <small>{x.number}</small>
                  <b>{x.title}</b>
                  <span>{money(x.total, x.currency)}</span>
                </div>
                <ArrowUpRight size={20} />
              </Link>
            ))
          ) : (
            <div className="clear">
              <CheckCircle2 size={37} />
              <h3>{t("Tidak ada tugas tertunda.", "You’re all caught up.")}</h3>
              <p>
                {t(
                  "Persetujuan baru akan muncul di sini.",
                  "New approval assignments will appear here.",
                )}
              </p>
            </div>
          )}
        </section>
      </div>
      {d.budgets.length > 0 && (
        <section className="panel budget-panel">
          <div className="panel-head">
            <h2>{t("Posisi anggaran", "Budget position")}</h2>
            <span className="muted">Reserved → committed → actual</span>
          </div>
          <div className="budget-grid">
            {d.budgets.map((b) => {
              const used =
                Number(b.reserved) + Number(b.committed) + Number(b.actual);
              return (
                <div key={b.id}>
                  <h3>{b.name}</h3>
                  <strong>
                    {money(Number(b.allocated) - used, b.currency)}
                  </strong>
                  <small>
                    {t("Tersedia dari", "Available of")}{" "}
                    {money(b.allocated ?? 0, b.currency)}
                  </small>
                  <div className="budget-bar">
                    <span
                      style={{
                        width:
                          Math.min(100, (used / Number(b.allocated)) * 100) +
                          "%",
                      }}
                    />
                  </div>
                  <div className="budget-legend">
                    <span>Reserved {money(b.reserved ?? 0, b.currency)}</span>
                    <span>Committed {money(b.committed ?? 0, b.currency)}</span>
                    <span>Actual {money(b.actual ?? 0, b.currency)}</span>
                  </div>
                </div>
              );
            })}
          </div>
        </section>
      )}
    </>
  );
}
function Documents() {
  const { kind = "pr" } = useParams();
  const k = kind as Kind;
  const { me, t } = useContext(Context);
  const [search, setSearch] = useState(""),
    [status, setStatus] = useState(""),
    [page, setPage] = useState(1);
  const q = useQuery({
    queryKey: ["documents", k, search, status, page],
    queryFn: () =>
      api<Page<Doc>>(
        `documents?kind=${k}&search=${encodeURIComponent(search)}&status=${status}&page=${page}`,
      ),
  });
  return (
    <>
      <Heading
        eyebrow="PROCUREMENT / WORKBENCH"
        title={labels[k] ? t(...labels[k]) : kind}
        description={t(
          "Kelola dokumen, lihat status, dan lanjutkan proses pengadaan.",
          "Manage documents, follow status, and move procurement forward.",
        )}
      >
        <Link className="secondary" to={"/data-tools?kind=" + k}>
          <Download size={16} />
          {t("Ekspor", "Export")}
        </Link>
        {me.permissions.includes(k + ".create") && (
          <Link className="primary" to={"/document/new/" + k}>
            <Plus size={17} />
            {t("Buat dokumen", "New document")}
          </Link>
        )}
      </Heading>
      <div className="list-toolbar">
        <div className="search">
          <Search size={18} />
          <input
            aria-label="Cari dokumen"
            placeholder={t(
              "Cari judul atau nomor dokumen…",
              "Search title or document number…",
            )}
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
        </div>
        <select
          aria-label="Filter status"
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
        >
          <option value="">{t("Semua status", "All statuses")}</option>
          {[
            "draft",
            "pending_approval",
            "approved",
            "published",
            "technical_open",
            "commercial_open",
            "awarded",
            "issued",
            "partially_received",
            "received",
            "mismatch",
            "matched",
            "paid",
            "posted",
            "cancelled",
          ].map((s) => (
            <option key={s}>{s}</option>
          ))}
        </select>
        <button
          className="iconbtn"
          aria-label="Muat ulang"
          onClick={() => q.refetch()}
        >
          <RefreshCw size={18} />
        </button>
      </div>
      <ErrorBox error={q.error} />
      {q.isLoading ? (
        <Loading />
      ) : q.data?.data.length ? (
        <section className="panel table-wrap">
          <table>
            <thead>
              <tr>
                <th>{t("Dokumen", "Document")}</th>
                <th>Status</th>
                <th>{t("Nilai", "Value")}</th>
                <th>{t("Dibuat", "Created")}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((d) => (
                <tr key={d.id}>
                  <td>
                    <Link className="table-title" to={"/document/" + d.id}>
                      {d.title}
                      <small>{d.number}</small>
                    </Link>
                  </td>
                  <td>
                    <Status value={d.status} />
                  </td>
                  <td className="numeric">{money(d.total, d.currency)}</td>
                  <td>{date(d.created_at)}</td>
                  <td>
                    <Link
                      aria-label={"Buka " + d.number}
                      to={"/document/" + d.id}
                    >
                      <ArrowUpRight size={18} />
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="pagination">
            <span>
              {q.data.total} {t("dokumen", "documents")}
            </span>
            <button disabled={page === 1} onClick={() => setPage(page - 1)}>
              {t("Sebelumnya", "Previous")}
            </button>
            <span>
              {page} / {q.data.last_page}
            </span>
            <button
              disabled={page === q.data.last_page}
              onClick={() => setPage(page + 1)}
            >
              {t("Berikutnya", "Next")}
            </button>
          </div>
        </section>
      ) : (
        <Empty />
      )}
    </>
  );
}
const blankLine = (): Line => ({
  description: "",
  uom: "unit",
  quantity: "1",
  unit_price: "0",
  discount_percent: "0",
  tax_percent: "0",
});
function Editor() {
  const params = useParams();
  const [sp] = useSearchParams();
  const id = params.id;
  const k = (params.kind ?? "pr") as Kind;
  const source = sp.get("source");
  const nav = useNavigate();
  const { me, t } = useContext(Context);
  const qc = useQueryClient();
  const look = useQuery({
    queryKey: ["lookups"],
    queryFn: () => api<Lookups>("lookups"),
  });
  const detail = useQuery({
    queryKey: ["editor", id, source],
    queryFn: () => api<Detail>("documents/" + (id ?? source)),
    enabled: !!(id ?? source),
  });
  const [form, setForm] = useState({
    kind: k,
    title: "",
    legal_entity_id: 0,
    cost_center_id: 0,
    budget_id: 0,
    vendor_id: me.membership.vendor_id ?? 0,
    source_id: source ? Number(source) : 0,
    currency: "IDR",
    exchange_rate: "1",
    needed_at: "",
    deadline: "",
    version: 1,
  });
  const [lines, setLines] = useState<Line[]>([blankLine()]),
    [data, setData] = useState<BusinessData>({
      justification: "",
      method: "rfq",
      envelopes: 2,
      opening_quorum: 2,
      criteria: [
        { name: "Technical compliance", weight: 60 },
        { name: "Commercial value", weight: 40 },
      ],
    }),
    [error, setError] = useState<unknown>(),
    [busy, setBusy] = useState(false),
    [dirty, setDirty] = useState(false);
  useEffect(() => {
    if (look.data && !id && !source)
      setForm((f) => ({
        ...f,
        legal_entity_id: look.data.legal_entities[0]?.id ?? 0,
        cost_center_id: look.data.cost_centers[0]?.id ?? 0,
        budget_id: look.data.budgets[0]?.id ?? 0,
      }));
  }, [look.data, id, source]);
  useEffect(() => {
    if (detail.data) {
      const d = detail.data.document;
      setForm({
        kind: id ? d.kind : k,
        title: id ? d.title : labels[k][0] + " · " + d.title,
        legal_entity_id: d.legal_entity_id,
        cost_center_id: d.cost_center_id,
        budget_id: d.budget_id ?? 0,
        vendor_id: d.vendor_id ?? me.membership.vendor_id ?? 0,
        source_id: id ? (d.source_id ?? 0) : d.id,
        currency: d.currency,
        exchange_rate: d.exchange_rate,
        needed_at: d.needed_at?.slice(0, 10) ?? "",
        deadline: d.deadline?.slice(0, 16) ?? "",
        version: d.version,
      });
      setLines(
        (d.lines ?? []).map((l) => ({
          ...l,
          id: undefined,
          source_line_id: id ? l.source_line_id : l.id,
        })),
      );
      if (id) setData(d.data);
    }
  }, [detail.data, id, k, me.membership.vendor_id]);
  useEffect(() => {
    const fn = (e: BeforeUnloadEvent) => {
      if (dirty) e.preventDefault();
    };
    window.addEventListener("beforeunload", fn);
    return () => window.removeEventListener("beforeunload", fn);
  }, [dirty]);
  function change<K extends keyof typeof form>(
    key: K,
    value: (typeof form)[K],
  ) {
    setForm((f) => ({ ...f, [key]: value }));
    setDirty(true);
  }
  function updateLine(i: number, key: keyof Line, value: string) {
    setLines((ls) => ls.map((l, n) => (n === i ? { ...l, [key]: value } : l)));
    setDirty(true);
  }
  async function save(e: React.FormEvent) {
    e.preventDefault();
    setError(undefined);
    setBusy(true);
    try {
      const payload = {
        ...form,
        budget_id: form.budget_id || null,
        vendor_id: form.vendor_id || null,
        source_id: form.source_id || null,
        needed_at: form.needed_at || null,
        deadline: form.deadline ? new Date(form.deadline).toISOString() : null,
        data,
        lines,
      };
      const d = await api<Doc>(
        "documents" + (id ? "/" + id : ""),
        id ? "PUT" : "POST",
        payload,
      );
      setDirty(false);
      qc.invalidateQueries();
      nav("/document/" + d.id);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }
  if (look.isLoading || detail.isLoading) return <Loading />;
  if (look.error || detail.error)
    return <ErrorBox error={look.error ?? detail.error} />;
  const options = (key: string) => look.data?.[key] ?? [];
  return (
    <>
      <Heading
        eyebrow="PROCUREMENT / DOCUMENT EDITOR"
        title={
          id ? t("Edit draft", "Edit draft") : t("Dokumen baru", "New document")
        }
        description={t(
          "Simpan sebagai draft, lalu ajukan melalui alur persetujuan.",
          "Save a draft, then submit through the approval workflow.",
        )}
      />
      <form onSubmit={save} onChange={() => setDirty(true)}>
        <div className="editor-layout">
          <div>
            <section className="panel form-section">
              <div className="section-title">
                <span>01</span>
                <h2>{t("Identitas dokumen", "Document identity")}</h2>
              </div>
              <Field label={t("Judul", "Title")}>
                <input
                  required
                  value={form.title}
                  onChange={(e) => change("title", e.target.value)}
                />
              </Field>
              <div className="fields-grid">
                <Field label="Legal entity">
                  <select
                    required
                    value={form.legal_entity_id}
                    onChange={(e) =>
                      change("legal_entity_id", Number(e.target.value))
                    }
                  >
                    <option value="">Pilih</option>
                    {options("legal_entities").map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.name}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Cost center">
                  <select
                    required
                    value={form.cost_center_id}
                    onChange={(e) =>
                      change("cost_center_id", Number(e.target.value))
                    }
                  >
                    <option value="">Pilih</option>
                    {options("cost_centers").map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.name}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label={t("Anggaran", "Budget")}>
                  <select
                    value={form.budget_id}
                    onChange={(e) =>
                      change("budget_id", Number(e.target.value))
                    }
                  >
                    <option value="0">—</option>
                    {options("budgets").map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.name}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label={t("Pemasok", "Supplier")}>
                  <select
                    value={form.vendor_id}
                    onChange={(e) =>
                      change("vendor_id", Number(e.target.value))
                    }
                  >
                    <option value="0">—</option>
                    {options("vendors")
                      .filter((v) => v.status === "approved")
                      .map((o) => (
                        <option key={o.id} value={o.id}>
                          {o.name}
                        </option>
                      ))}
                  </select>
                </Field>
                <Field label={t("Mata uang", "Currency")}>
                  <input
                    required
                    minLength={3}
                    maxLength={3}
                    value={form.currency}
                    onChange={(e) =>
                      change("currency", e.target.value.toUpperCase())
                    }
                  />
                </Field>
                <Field
                  label={t(
                    "Kurs ke mata uang anggaran",
                    "Rate to budget currency",
                  )}
                >
                  <input
                    required
                    type="number"
                    step="0.00000001"
                    min="0.00000001"
                    value={form.exchange_rate}
                    onChange={(e) => change("exchange_rate", e.target.value)}
                  />
                </Field>
                <Field label={t("Tanggal dibutuhkan", "Required date")}>
                  <input
                    type="date"
                    value={form.needed_at}
                    onChange={(e) => change("needed_at", e.target.value)}
                  />
                </Field>
                <Field label="Source document ID">
                  <input
                    type="number"
                    value={form.source_id || ""}
                    onChange={(e) =>
                      change("source_id", Number(e.target.value))
                    }
                    readOnly={!!source}
                  />
                </Field>
              </div>
              <Field
                label={t("Justifikasi / catatan", "Justification / notes")}
              >
                <textarea
                  rows={3}
                  value={data.justification ?? ""}
                  onChange={(e) =>
                    setData({ ...data, justification: e.target.value })
                  }
                />
              </Field>
              {form.kind === "invoice" && (
                <div className="fields-grid">
                  <Field label="Invoice number">
                    <input
                      required
                      value={data.invoice_number ?? ""}
                      onChange={(e) =>
                        setData({ ...data, invoice_number: e.target.value })
                      }
                    />
                  </Field>
                  <Field label="Due date">
                    <input
                      type="date"
                      value={data.due_date ?? ""}
                      onChange={(e) =>
                        setData({ ...data, due_date: e.target.value })
                      }
                    />
                  </Field>
                </div>
              )}
            </section>
            <section className="panel form-section">
              <div className="section-title">
                <span>02</span>
                <h2>{t("Rincian barang & jasa", "Goods & services")}</h2>
              </div>
              {lines.map((l, i) => (
                <div className="line-editor" key={i}>
                  <div className="line-top">
                    <b>LINE {String(i + 1).padStart(2, "0")}</b>
                    <button
                      type="button"
                      className="iconbtn"
                      aria-label={"Hapus baris " + (i + 1)}
                      disabled={lines.length === 1}
                      onClick={() =>
                        setLines((ls) => ls.filter((_, n) => n !== i))
                      }
                    >
                      <Trash2 size={16} />
                    </button>
                  </div>
                  <Field label={t("Deskripsi", "Description")}>
                    <input
                      required
                      value={l.description}
                      onChange={(e) =>
                        updateLine(i, "description", e.target.value)
                      }
                    />
                  </Field>
                  <div className="line-fields">
                    <Field label="Quantity">
                      <input
                        required
                        type="number"
                        min="0.0001"
                        step="0.0001"
                        value={l.quantity}
                        onChange={(e) =>
                          updateLine(i, "quantity", e.target.value)
                        }
                      />
                    </Field>
                    <Field label="UOM">
                      <input
                        required
                        value={l.uom}
                        onChange={(e) => updateLine(i, "uom", e.target.value)}
                      />
                    </Field>
                    <Field label="Unit price">
                      <input
                        required
                        type="number"
                        min="0"
                        step="0.0001"
                        value={l.unit_price}
                        onChange={(e) =>
                          updateLine(i, "unit_price", e.target.value)
                        }
                      />
                    </Field>
                    <Field label="Discount %">
                      <input
                        type="number"
                        min="0"
                        max="100"
                        step="0.0001"
                        value={l.discount_percent}
                        onChange={(e) =>
                          updateLine(i, "discount_percent", e.target.value)
                        }
                      />
                    </Field>
                    <Field label="Tax %">
                      <input
                        type="number"
                        min="0"
                        max="100"
                        step="0.0001"
                        value={l.tax_percent}
                        onChange={(e) =>
                          updateLine(i, "tax_percent", e.target.value)
                        }
                      />
                    </Field>
                  </div>
                  {["receipt", "return", "invoice", "credit"].includes(
                    form.kind,
                  ) && (
                    <Field label="Source line ID">
                      <input
                        required
                        type="number"
                        value={l.source_line_id ?? ""}
                        onChange={(e) =>
                          setLines((ls) =>
                            ls.map((x, n) =>
                              n === i
                                ? {
                                    ...x,
                                    source_line_id: Number(e.target.value),
                                  }
                                : x,
                            ),
                          )
                        }
                      />
                    </Field>
                  )}
                </div>
              ))}
              <button
                className="secondary"
                type="button"
                onClick={() => setLines([...lines, blankLine()])}
              >
                <Plus size={16} />
                {t("Tambah baris", "Add line")}
              </button>
            </section>
            {form.kind === "tender" && (
              <section className="panel form-section">
                <div className="section-title">
                  <span>03</span>
                  <h2>Sourcing configuration</h2>
                </div>
                <div className="fields-grid">
                  <Field label="Deadline (waktu perangkat; disimpan UTC)">
                    <input
                      required
                      type="datetime-local"
                      value={form.deadline}
                      onChange={(e) => change("deadline", e.target.value)}
                    />
                  </Field>
                  <Field label="Method">
                    <select
                      value={data.method}
                      onChange={(e) =>
                        setData({ ...data, method: e.target.value })
                      }
                    >
                      <option value="rfq">RFQ competitive</option>
                      <option value="rfp">Tender / RFP</option>
                      <option value="direct">Direct purchase</option>
                    </select>
                  </Field>
                  <Field label="Envelopes">
                    <select
                      value={data.envelopes}
                      onChange={(e) =>
                        setData({ ...data, envelopes: Number(e.target.value) })
                      }
                    >
                      <option value="2">Technical + commercial</option>
                      <option value="1">Single envelope</option>
                    </select>
                  </Field>
                  <Field label="Opening quorum">
                    <input
                      type="number"
                      min="1"
                      required
                      value={data.opening_quorum}
                      onChange={(e) =>
                        setData({
                          ...data,
                          opening_quorum: Number(e.target.value),
                        })
                      }
                    />
                  </Field>
                </div>
                <Field label="Opening panel">
                  <select
                    multiple
                    required
                    value={(data.opening_user_ids ?? []).map(String)}
                    onChange={(e) =>
                      setData({
                        ...data,
                        opening_user_ids: Array.from(
                          e.target.selectedOptions,
                          (x) => Number(x.value),
                        ),
                      })
                    }
                  >
                    {options("users")
                      .filter((u) =>
                        ["procurement", "manager"].includes(u.role ?? ""),
                      )
                      .map((u) => (
                        <option key={u.id} value={u.id}>
                          {u.name}
                        </option>
                      ))}
                  </select>
                </Field>
                <Field label="Evaluators">
                  <select
                    multiple
                    required
                    value={(data.evaluator_ids ?? []).map(String)}
                    onChange={(e) =>
                      setData({
                        ...data,
                        evaluator_ids: Array.from(
                          e.target.selectedOptions,
                          (x) => Number(x.value),
                        ),
                      })
                    }
                  >
                    {options("users")
                      .filter((u) => u.role === "evaluator")
                      .map((u) => (
                        <option key={u.id} value={u.id}>
                          {u.name}
                        </option>
                      ))}
                  </select>
                </Field>
                {data.criteria?.map((c, i) => (
                  <div className="fields-grid" key={i}>
                    <Field label="Criterion">
                      <input
                        required
                        value={c.name}
                        onChange={(e) =>
                          setData({
                            ...data,
                            criteria: data.criteria?.map((x, n) =>
                              n === i ? { ...x, name: e.target.value } : x,
                            ),
                          })
                        }
                      />
                    </Field>
                    <Field label="Weight %">
                      <input
                        required
                        type="number"
                        min="1"
                        max="100"
                        value={c.weight}
                        onChange={(e) =>
                          setData({
                            ...data,
                            criteria: data.criteria?.map((x, n) =>
                              n === i
                                ? { ...x, weight: Number(e.target.value) }
                                : x,
                            ),
                          })
                        }
                      />
                    </Field>
                  </div>
                ))}
              </section>
            )}
          </div>
          <aside className="editor-summary panel">
            <div className="eyebrow">DOCUMENT SUMMARY</div>
            <h3>{labels[form.kind]?.[0]}</h3>
            <p>
              {lines.length} line items · {form.currency}
            </p>
            <div className="summary-total">
              <small>{t("Estimasi tampilan", "Display estimate")}</small>
              <strong>
                {money(
                  lines.reduce(
                    (a, l) =>
                      a +
                      Number(l.quantity) *
                        Number(l.unit_price) *
                        (1 - Number(l.discount_percent) / 100) *
                        (1 + Number(l.tax_percent) / 100),
                    0,
                  ),
                  form.currency,
                )}
              </strong>
            </div>
            <p className="muted small">
              {t(
                "Total final dihitung backend menggunakan decimal dan pembulatan per baris.",
                "Final totals are calculated by the backend using decimal arithmetic and per-line rounding.",
              )}
            </p>
            <ErrorBox error={error} />
            <button disabled={busy} className="primary full" type="submit">
              <Check size={17} />
              {busy
                ? t("Menyimpan…", "Saving…")
                : t("Simpan draft", "Save draft")}
            </button>
            <button
              type="button"
              className="secondary full"
              onClick={() => {
                if (
                  !dirty ||
                  confirm(
                    t(
                      "Tinggalkan perubahan yang belum disimpan?",
                      "Discard unsaved changes?",
                    ),
                  )
                )
                  nav(-1);
              }}
            >
              {t("Batal", "Cancel")}
            </button>
            <div className="summary-note">
              <LockKeyhole size={17} />
              {t(
                "Persetujuan dan status selalu divalidasi server.",
                "Approvals and state transitions are validated by the server.",
              )}
            </div>
          </aside>
        </div>
      </form>
    </>
  );
}

function DocumentView() {
  const { id } = useParams();
  const { me, t } = useContext(Context);
  const q = useQuery({
    queryKey: ["document", id],
    queryFn: () => api<Detail>("documents/" + id),
  });
  const look = useQuery({
    queryKey: ["lookups"],
    queryFn: () => api<Lookups>("lookups"),
  });
  const qc = useQueryClient();
  const [modal, setModal] = useState(""),
    [reason, setReason] = useState(""),
    [selection, setSelection] = useState<number[]>([]),
    [scores, setScores] = useState<string[]>([]),
    [prices, setPrices] = useState<string[]>([]),
    [busy, setBusy] = useState(false),
    [error, setError] = useState<unknown>();
  const d = q.data?.document;
  const bids = useQuery({
    queryKey: ["bids", id],
    queryFn: () => api<Bid[]>("tenders/" + id + "/bids"),
    enabled: d?.kind === "tender",
  });
  if (q.isLoading) return <Loading />;
  if (!d) return <ErrorBox error={q.error} />;
  const can = (s: string) => me.permissions.includes(s);
  const vendor = me.membership.role.startsWith("vendor");
  function ask(action: string) {
    setModal(action);
    setReason("");
    setSelection([]);
    setPrices((d?.lines ?? []).map((x) => x.unit_price));
    setScores((d?.data.criteria ?? []).map(() => ""));
    setError(undefined);
  }
  async function run(e: React.FormEvent) {
    e.preventDefault();
    if (!d) return;
    setBusy(true);
    setError(undefined);
    try {
      let path = `documents/${d.id}/actions/${modal}`;
      let data: unknown = { version: d.version, reason };
      if (modal === "pay")
        data = { version: d.version, reason, payment_reference: reason };
      if (
        [
          "publish",
          "bid",
          "technical",
          "commercial",
          "evaluate",
          "award",
        ].includes(modal)
      ) {
        path =
          `tenders/${d.id}/` +
          (
            {
              publish: "publish",
              bid: "bids",
              technical: "open",
              commercial: "open",
              evaluate: "evaluate",
              award: "award",
            } as Record<string, string>
          )[modal];
        if (modal === "publish") data = { vendor_ids: selection };
        if (modal === "technical" || modal === "commercial")
          data = { envelope: modal };
        if (modal === "award") data = { vendor_ids: selection, reason };
        if (modal === "evaluate")
          data = {
            vendor_id: selection[0],
            scores: scores.map(Number),
            comment: reason,
            conflict_declared: false,
          };
        if (modal === "bid")
          data = {
            technical: { summary: reason },
            commercial: {
              lines: d.lines?.map((l, i) => ({
                source_line_id: l.id,
                quantity: l.quantity,
                unit_price: prices[i],
              })),
              currency: d.currency,
              lead_days: 7,
              payment_terms: "Net 30 days",
            },
          };
      }
      await api(path, "POST", data);
      setModal("");
      qc.invalidateQueries();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }
  const next: Partial<Record<Kind, Kind[]>> = {
    pr: ["tender", "po"],
    tender: ["contract", "po"],
    contract: ["po"],
    po: ["receipt", "invoice"],
    receipt: ["return"],
    invoice: ["credit"],
  };
  return (
    <>
      <div className="breadcrumbs">
        <Link to={"/documents/" + d.kind}>{t(...labels[d.kind])}</Link>
        <ChevronRight size={14} />
        <span>{d.number}</span>
      </div>
      <Heading eyebrow={d.number + " / VERSION " + d.version} title={d.title}>
        <Status value={d.status} />
        <a className="secondary" href={"/api/v1/documents/" + d.id + "/pdf"}>
          <Download size={16} />
          PDF
        </a>
        {["draft", "returned", "rejected"].includes(d.status) &&
          can(d.kind + ".edit") && (
            <Link className="primary" to={"/document/" + d.id + "/edit"}>
              {t("Edit draft", "Edit draft")}
            </Link>
          )}
      </Heading>
      <div className="detail-layout">
        <div>
          <section className="panel document-cover">
            <div>
              <div className="eyebrow">
                {d.kind.toUpperCase()} / PROCUREMENT RECORD
              </div>
              <h2>{d.number}</h2>
              <p>
                {d.data.justification ||
                  t(
                    "Dokumen pengadaan tersimpan.",
                    "Stored procurement record.",
                  )}
              </p>
            </div>
            <div className="cover-total">
              <small>{t("Nilai dokumen", "Document value")}</small>
              <strong>{money(d.total, d.currency)}</strong>
              <span>Rate snapshot {d.exchange_rate}</span>
            </div>
          </section>
          <section className="panel">
            <div className="panel-head">
              <h2>{t("Rincian dokumen", "Document details")}</h2>
            </div>
            <dl className="details-grid">
              <div>
                <dt>Legal entity</dt>
                <dd>
                  {look.data?.legal_entities.find(
                    (x) => x.id === d.legal_entity_id,
                  )?.name ?? d.legal_entity_id}
                </dd>
              </div>
              <div>
                <dt>Cost center</dt>
                <dd>
                  {look.data?.cost_centers.find(
                    (x) => x.id === d.cost_center_id,
                  )?.name ?? d.cost_center_id}
                </dd>
              </div>
              <div>
                <dt>Supplier</dt>
                <dd>
                  {look.data?.vendors.find((x) => x.id === d.vendor_id)?.name ??
                    "—"}
                </dd>
              </div>
              <div>
                <dt>Required date</dt>
                <dd>{date(d.needed_at)}</dd>
              </div>
              {d.deadline && (
                <div>
                  <dt>Submission deadline</dt>
                  <dd>
                    {new Date(d.deadline).toLocaleString("id-ID", {
                      timeZone: "Asia/Jakarta",
                    })}{" "}
                    WIB
                  </dd>
                </div>
              )}
              {d.source_id && (
                <div>
                  <dt>Source</dt>
                  <dd>
                    <Link to={"/document/" + d.source_id}>
                      Document #{d.source_id}
                      <ArrowUpRight size={14} />
                    </Link>
                  </dd>
                </div>
              )}
            </dl>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Description / line ID</th>
                    <th>Qty</th>
                    <th>Unit price</th>
                    <th>Tax</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  {d.lines?.map((l) => (
                    <tr key={l.id}>
                      <td>
                        {l.description}
                        <small className="block muted">
                          Line #{l.id} · {l.uom}
                        </small>
                      </td>
                      <td>{Number(l.quantity)}</td>
                      <td>{money(l.unit_price, d.currency)}</td>
                      <td>{money(l.tax ?? 0, d.currency)}</td>
                      <td className="numeric">
                        {money(l.total ?? 0, d.currency)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
          {d.data.match_issues && d.data.match_issues.length > 0 && (
            <section className="panel mismatch">
              <h2>
                <AlertTriangle />
                Matching exceptions
              </h2>
              {d.data.match_issues.map((x, i) => (
                <p key={i}>
                  Line #{x.line_id} · {x.code.replaceAll("_", " ")} {x.field}
                </p>
              ))}
              <p>
                Perbaiki draft/referensi penerimaan sebelum meminta matching
                ulang.
              </p>
            </section>
          )}
          {d.kind === "tender" && (
            <section className="panel tender-panel">
              <div className="panel-head">
                <h2>
                  {t("Penawaran & perbandingan", "Submissions & comparison")}
                </h2>
                <LockKeyhole size={20} />
              </div>
              <ErrorBox error={bids.error} />
              {bids.isLoading ? (
                <Loading />
              ) : bids.data?.length ? (
                <div className="bids-grid">
                  {bids.data.map((b) => (
                    <div className="bid-card" key={b.id}>
                      <h3>
                        {look.data?.vendors.find((v) => v.id === b.vendor_id)
                          ?.name ?? "Vendor #" + b.vendor_id}
                      </h3>
                      <small>
                        Revision {b.revision} · {date(b.submitted_at)}
                      </small>
                      {b.technical ? (
                        <p>{b.technical.summary}</p>
                      ) : (
                        <div className="sealed">
                          <LockKeyhole size={25} />
                          Technical envelope sealed
                        </div>
                      )}
                      {b.commercial ? (
                        <>
                          <div className="bid-value">
                            {money(
                              b.commercial.lines.reduce(
                                (a, l) =>
                                  a + Number(l.quantity) * Number(l.unit_price),
                                0,
                              ),
                              b.commercial.currency,
                            )}
                          </div>
                          <p>
                            Lead time {b.commercial.lead_days} days ·{" "}
                            {b.commercial.payment_terms}
                          </p>
                        </>
                      ) : (
                        <div className="sealed">
                          <LockKeyhole size={25} />
                          Commercial envelope sealed
                        </div>
                      )}
                      <small className="hash" title={b.hash}>
                        SHA256 {b.hash.slice(0, 18)}…
                      </small>
                    </div>
                  ))}
                </div>
              ) : (
                <Empty
                  text={t(
                    "Belum ada penawaran dikirim.",
                    "No submissions yet.",
                  )}
                />
              )}
            </section>
          )}
          <section className="panel">
            <div className="panel-head">
              <h2>{t("Lampiran", "Attachments")}</h2>
              {d.status === "draft" && can(d.kind + ".edit") && (
                <label className="secondary upload-label">
                  <Plus size={16} />
                  Upload
                  <input
                    type="file"
                    accept=".pdf,.png,.jpg,.jpeg,.txt,.csv,.xlsx,.docx"
                    onChange={async (e) => {
                      const f = e.target.files?.[0];
                      if (!f) return;
                      try {
                        await upload("documents/" + d.id + "/attachments", f);
                        qc.invalidateQueries({ queryKey: ["document", id] });
                      } catch (e) {
                        setError(e);
                      }
                      e.target.value = "";
                    }}
                  />
                </label>
              )}
            </div>
            <ErrorBox error={error} />
            {q.data?.attachments.length ? (
              q.data.attachments.map((a) => (
                <div className="attachment" key={a.id}>
                  <FileText size={18} />
                  <span>
                    {a.name}
                    <small>{(a.size / 1024).toFixed(1)} KB</small>
                  </span>
                  <Status value={a.status} />
                  {a.status === "clean" && (
                    <a
                      aria-label={"Download " + a.name}
                      href={"/api/v1/attachments/" + a.id + "/download"}
                    >
                      <Download size={17} />
                    </a>
                  )}
                </div>
              ))
            ) : (
              <p className="muted">
                {t(
                  "Belum ada lampiran. File akan dikarantina sampai pemindaian selesai.",
                  "No attachments. Uploaded files remain quarantined until scanning completes.",
                )}
              </p>
            )}
          </section>
        </div>
        <aside>
          <section className="panel action-panel">
            <div className="eyebrow">NEXT ACTION</div>
            <h3>{t("Lanjutkan proses", "Move it forward")}</h3>
            {(d.status === "draft" || d.status === "matched") &&
              can(d.kind + ".submit") && (
                <button className="primary full" onClick={() => ask("submit")}>
                  <Send size={17} />
                  {t("Ajukan persetujuan", "Submit for approval")}
                </button>
              )}
            {d.status === "pending_approval" && can("approve") && (
              <>
                <button className="primary full" onClick={() => ask("approve")}>
                  <Check size={16} />
                  {t("Setujui", "Approve")}
                </button>
                <button
                  className="secondary full"
                  onClick={() => ask("return")}
                >
                  {t("Kembalikan", "Return for revision")}
                </button>
                <button className="danger full" onClick={() => ask("reject")}>
                  {t("Tolak", "Reject")}
                </button>
              </>
            )}
            {d.kind === "po" && d.status === "approved" && can("po.issue") && (
              <button className="primary full" onClick={() => ask("issue")}>
                Issue purchase order
              </button>
            )}
            {d.kind === "po" && d.status === "issued" && vendor && (
              <button
                className="primary full"
                onClick={() => ask("acknowledge")}
              >
                Acknowledge order
              </button>
            )}
            {d.kind === "contract" &&
              d.status === "approved" &&
              can("contract.activate") && (
                <button
                  className="primary full"
                  onClick={() => ask("activate")}
                >
                  Activate contract
                </button>
              )}
            {["receipt", "return", "credit"].includes(d.kind) &&
              d.status === "draft" &&
              can(d.kind + ".post") && (
                <button className="primary full" onClick={() => ask("post")}>
                  Post document
                </button>
              )}
            {d.kind === "invoice" &&
              ["draft", "mismatch"].includes(d.status) &&
              can("invoice.match") && (
                <button className="primary full" onClick={() => ask("match")}>
                  Run three-way matching
                </button>
              )}
            {d.kind === "invoice" &&
              d.status === "approved" &&
              can("invoice.pay") && (
                <button className="primary full" onClick={() => ask("pay")}>
                  Record payment reference
                </button>
              )}
            {d.kind === "tender" && (
              <>
                {d.status === "draft" && can("tender.publish") && (
                  <button
                    className="primary full"
                    onClick={() => ask("publish")}
                  >
                    Invite suppliers & publish
                  </button>
                )}
                {d.status === "published" && vendor && (
                  <button className="primary full" onClick={() => ask("bid")}>
                    Submit sealed bid
                  </button>
                )}
                {d.status === "published" && can("tender.open") && (
                  <button
                    className="primary full"
                    onClick={() => ask("technical")}
                  >
                    Vote: open technical
                  </button>
                )}
                {d.status === "technical_open" && can("tender.open") && (
                  <button
                    className="primary full"
                    onClick={() => ask("commercial")}
                  >
                    Vote: open commercial
                  </button>
                )}
                {["technical_open", "commercial_open"].includes(d.status) &&
                  can("tender.evaluate") && (
                    <button
                      className="primary full"
                      onClick={() => ask("evaluate")}
                    >
                      Evaluate vendor
                    </button>
                  )}
                {d.status === "commercial_open" && can("tender.award") && (
                  <button className="primary full" onClick={() => ask("award")}>
                    Award decision
                  </button>
                )}
              </>
            )}
            {(next[d.kind] ?? [])
              .filter((k) => can(k + ".create"))
              .map((k) => (
                <Link
                  key={k}
                  className="secondary full"
                  to={"/document/new/" + k + "?source=" + d.id}
                >
                  <Plus size={16} />
                  {t(...labels[k])}
                </Link>
              ))}
            {[
              "draft",
              "pending_approval",
              "approved",
              "issued",
              "acknowledged",
              "published",
            ].includes(d.status) &&
              (me.user.id === d.created_by ||
                me.membership.role === "manager") && (
                <button className="text-danger" onClick={() => ask("cancel")}>
                  Cancel with reason
                </button>
              )}
            <p className="small muted">
              {t(
                "Server memeriksa status, izin, anggaran, dan relasi sebelum tindakan diterima.",
                "The server checks state, permissions, budget, and references before accepting an action.",
              )}
            </p>
          </section>
          <section className="panel approval-panel">
            <div className="eyebrow">APPROVAL HISTORY</div>
            <h3>{t("Jejak keputusan", "Decision trail")}</h3>
            {q.data?.approvals.length ? (
              q.data.approvals.map((a) => (
                <div className="approval" key={a.id}>
                  <span
                    className={
                      a.status === "approved"
                        ? "approval-dot done"
                        : "approval-dot"
                    }
                  >
                    {a.status === "approved" ? <Check size={13} /> : a.position}
                  </span>
                  <div>
                    <b>{a.approver_name}</b>
                    <small>
                      Round {a.round} / Step {a.position}
                    </small>
                    <Status value={a.status} />
                    {a.reason && <p>{a.reason}</p>}
                  </div>
                </div>
              ))
            ) : (
              <p className="muted">
                {t(
                  "Alur dimulai saat diajukan.",
                  "Workflow starts on submission.",
                )}
              </p>
            )}
          </section>
          {q.data?.revisions.length !== 0 && (
            <section className="panel">
              <h3>Revision history</h3>
              {q.data?.revisions.map((x) => (
                <p key={x.id}>
                  Version {x.version} · {date(x.created_at)}
                </p>
              ))}
            </section>
          )}
        </aside>
      </div>
      {modal && (
        <Modal
          title={modal.replaceAll("_", " ").toUpperCase()}
          onClose={() => setModal("")}
        >
          <form onSubmit={run}>
            {["publish", "award", "evaluate"].includes(modal) && (
              <Field
                label={
                  modal === "evaluate"
                    ? "Vendor to evaluate"
                    : "Select suppliers"
                }
              >
                <select
                  required
                  multiple={modal !== "evaluate"}
                  value={
                    modal === "evaluate"
                      ? (selection[0] ?? "")
                      : selection.map(String)
                  }
                  onChange={(e) =>
                    setSelection(
                      Array.from(e.target.selectedOptions, (x) =>
                        Number(x.value),
                      ),
                    )
                  }
                >
                  <option value="" disabled>
                    Choose
                  </option>
                  {look.data?.vendors
                    .filter((v) => v.status === "approved")
                    .map((v) => (
                      <option key={v.id} value={v.id}>
                        {v.name}
                      </option>
                    ))}
                </select>
              </Field>
            )}
            {modal === "evaluate" &&
              d.data.criteria?.map((c, i) => (
                <Field label={c.name + " / " + c.weight + "% weight"} key={i}>
                  <input
                    type="number"
                    min="0"
                    max="100"
                    required
                    value={scores[i] ?? ""}
                    onChange={(e) =>
                      setScores((v) =>
                        v.map((x, n) => (n === i ? e.target.value : x)),
                      )
                    }
                  />
                </Field>
              ))}
            {modal === "bid" &&
              d.lines?.map((l, i) => (
                <Field
                  key={l.id}
                  label={
                    l.description +
                    " · " +
                    l.quantity +
                    " " +
                    l.uom +
                    " / Unit price"
                  }
                >
                  <input
                    type="number"
                    min="0"
                    step="0.0001"
                    required
                    value={prices[i] ?? ""}
                    onChange={(e) =>
                      setPrices((v) =>
                        v.map((x, n) => (n === i ? e.target.value : x)),
                      )
                    }
                  />
                </Field>
              ))}
            <Field
              label={
                modal === "pay"
                  ? "Payment reference"
                  : modal === "bid"
                    ? "Technical summary"
                    : "Reason / notes"
              }
            >
              <textarea
                required
                rows={3}
                minLength={modal === "award" ? 10 : 5}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
              />
            </Field>
            {modal === "evaluate" && (
              <p className="small muted">
                Submitting confirms you have no conflict of interest for this
                evaluation.
              </p>
            )}
            <ErrorBox error={error} />
            <button className="primary full" disabled={busy}>
              {busy ? "Memproses…" : "Confirm action"}
            </button>
          </form>
        </Modal>
      )}
    </>
  );
}
type FieldSpec = {
  name: string;
  label: string;
  type?: string;
  options?: Lookup[];
  required?: boolean;
};
const masterNames: Record<string, string> = {
  legal_entities: "Legal entities",
  departments: "Departments",
  cost_centers: "Cost centers",
  warehouses: "Warehouses",
  categories: "Categories",
  items: "Item catalog",
  tax_codes: "Tax codes",
  currencies: "Currencies",
  payment_terms: "Payment terms",
  procurement_plans: "Procurement plans",
};
type AdminRow = {
  id: number;
  name?: string;
  code?: string;
  email?: string;
  status?: string;
  currency?: string;
  allocated?: string;
  reserved?: string;
  committed?: string;
  actual?: string;
  role?: string;
  version?: number;
  kind?: string;
  action?: string;
  object_type?: string;
  object_id?: string;
  created_at?: string;
  active?: boolean;
  data?: string;
  steps?: string;
  reason?: string;
};
type Integrations = {
  connectors: {
    id: number;
    name: string;
    protocol: string;
    endpoint: string | null;
    enabled: boolean;
  }[];
  outbox: {
    id: number;
    type: string;
    status: string;
    attempts: number;
    last_error: string | null;
  }[];
};
function Admin() {
  const { table = "vendors" } = useParams();
  const { me, t } = useContext(Context);
  const [modal, setModal] = useState(false),
    [values, setValues] = useState<Record<string, string>>({
      currency: "IDR",
      year: "2026",
      role: "requester",
      kind: "pr",
      protocol: "rest",
    }),
    [selected, setSelected] = useState<number[]>([]),
    [error, setError] = useState<unknown>(),
    [busy, setBusy] = useState(false);
  const qc = useQueryClient();
  const look = useQuery({
    queryKey: ["lookups"],
    queryFn: () => api<Lookups>("lookups"),
  });
  const q = useQuery({
    queryKey: ["admin", table],
    queryFn: () =>
      api<AdminRow[] | Page<AdminRow> | Integrations>("admin/" + table),
  });
  const rows = Array.isArray(q.data)
    ? q.data
    : q.data && "data" in q.data
      ? q.data.data
      : [];
  const integration =
    q.data && !Array.isArray(q.data) && "connectors" in q.data ? q.data : null;
  const master = table in masterNames;
  const title =
    masterNames[table] ??
    (
      {
        vendors: t("Pemasok terverifikasi", "Supplier management"),
        budgets: t("Kontrol anggaran", "Budget control"),
        users: t("Pengguna & akses", "Users & access"),
        policies: t("Aturan persetujuan", "Approval policies"),
        integrations: t("Integrasi & antrian", "Integrations & outbox"),
        audit: "Audit trail",
      } as Record<string, string>
    )[table] ??
    table;
  let fields: FieldSpec[] = [];
  if (master)
    fields = [
      { name: "code", label: "Code" },
      { name: "name", label: "Name" },
    ];
  if (table === "vendors")
    fields = [
      { name: "code", label: "Vendor code" },
      { name: "name", label: "Company name" },
      { name: "email", label: "Business email", type: "email" },
      { name: "tax_id", label: "Tax / registration ID", required: false },
    ];
  if (table === "budgets")
    fields = [
      { name: "name", label: "Budget name" },
      {
        name: "legal_entity_id",
        label: "Legal entity",
        options: look.data?.legal_entities,
      },
      {
        name: "cost_center_id",
        label: "Cost center",
        options: look.data?.cost_centers,
      },
      { name: "year", label: "Fiscal year", type: "number" },
      { name: "currency", label: "Currency" },
      { name: "allocated", label: "Allocated amount", type: "number" },
    ];
  if (table === "users")
    fields = [
      { name: "name", label: "Full name" },
      { name: "email", label: "Email", type: "email" },
      {
        name: "password",
        label: "Initial password (14+ characters)",
        type: "password",
      },
      {
        name: "role",
        label: "Role",
        options: [
          "admin",
          "requester",
          "approver",
          "procurement",
          "manager",
          "evaluator",
          "legal",
          "receiver",
          "finance",
          "auditor",
          "vendor_admin",
          "vendor_staff",
        ].map((x, i) => ({ id: i, name: x, code: x })),
      },
      {
        name: "vendor_id",
        label: "Vendor (vendor roles)",
        options: look.data?.vendors,
        required: false,
      },
    ];
  if (table === "policies")
    fields = [
      { name: "name", label: "Policy name" },
      {
        name: "kind",
        label: "Document kind",
        options: ["pr", "po", "contract", "invoice", "tender"].map((x, i) => ({
          id: i,
          name: x,
          code: x,
        })),
      },
      { name: "quorum", label: "Approval quorum", type: "number" },
    ];
  if (table === "integrations")
    fields = [
      { name: "name", label: "Connector name" },
      {
        name: "protocol",
        label: "Protocol",
        options: ["rest", "soap", "sandbox"].map((x, i) => ({
          id: i,
          name: x,
          code: x,
        })),
      },
      {
        name: "endpoint",
        label: "HTTPS endpoint",
        type: "url",
        required: false,
      },
      {
        name: "secret",
        label: "API token / webhook secret",
        type: "password",
        required: false,
      },
    ];
  const createRight = master
    ? "master"
    : table === "vendors"
      ? "vendor.manage"
      : table === "budgets"
        ? "budget.manage"
        : table === "users"
          ? "users"
          : table === "policies"
            ? "policy"
            : table === "integrations"
              ? "integration.manage"
              : "";
  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(undefined);
    try {
      const payload: Record<string, unknown> = { ...values };
      for (const f of fields)
        if (
          f.type === "number" ||
          (f.options && !["role", "kind", "protocol"].includes(f.name))
        )
          payload[f.name] = values[f.name] ? Number(values[f.name]) : null;
      if (table === "policies") {
        payload.steps = [
          { approver_ids: selected, quorum: Number(values.quorum ?? 1) },
        ];
        delete payload.quorum;
      }
      await api("admin/" + table, "POST", payload);
      setModal(false);
      qc.invalidateQueries();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }
  async function action(path: string, data: unknown) {
    setError(undefined);
    try {
      await api(path, "POST", data);
      qc.invalidateQueries();
    } catch (e) {
      setError(e);
    }
  }
  return (
    <>
      <Heading
        eyebrow="CONTROL CENTER / GOVERNANCE"
        title={title}
        description={t(
          "Konfigurasi dan aktivitas dibatasi oleh peran serta organisasi.",
          "Configuration and activity follow role and organization boundaries.",
        )}
      >
        {createRight && me.permissions.includes(createRight) && (
          <button
            className="primary"
            onClick={() => {
              setValues({
                currency: "IDR",
                year: "2026",
                role: "requester",
                kind: "pr",
                quorum: "1",
                protocol: "rest",
              });
              setError(undefined);
              setModal(true);
            }}
          >
            <Plus size={17} />
            {t("Tambah", "Add record")}
          </button>
        )}
      </Heading>
      {master && (
        <div className="master-tabs">
          {Object.entries(masterNames).map(([key, name]) => (
            <NavLink key={key} to={"/admin/" + key}>
              {name}
            </NavLink>
          ))}
        </div>
      )}
      <ErrorBox error={error ?? q.error} />
      {q.isLoading ? (
        <Loading />
      ) : table === "vendors" ? (
        <div className="vendor-grid">
          {rows.length ? (
            rows.map((v) => (
              <section className="panel vendor-card" key={v.id}>
                <div className="vendor-top">
                  <span className="vendor-avatar">
                    {v.name?.slice(0, 2).toUpperCase()}
                  </span>
                  <Status value={v.status ?? "draft"} />
                </div>
                <small>{v.code}</small>
                <h2>{v.name}</h2>
                <p>{v.email}</p>
                <div className="vendor-bottom">
                  <span>Version {v.version}</span>
                  {me.permissions.includes("vendor.manage") && (
                    <div>
                      {v.status === "under_review" && (
                        <>
                          <button
                            className="secondary"
                            onClick={() => {
                              const reason = prompt(
                                "Alasan persetujuan (min. 5 karakter)",
                              );
                              if (reason)
                                action("vendors/" + v.id + "/review", {
                                  action: "approve",
                                  reason,
                                });
                            }}
                          >
                            Approve
                          </button>
                          <button
                            className="text-danger"
                            onClick={() => {
                              const reason = prompt("Alasan penolakan");
                              if (reason)
                                action("vendors/" + v.id + "/review", {
                                  action: "reject",
                                  reason,
                                });
                            }}
                          >
                            Reject
                          </button>
                        </>
                      )}
                      {v.status === "approved" && (
                        <button
                          className="text-danger"
                          onClick={() => {
                            const reason = prompt("Alasan suspend");
                            if (reason)
                              action("vendors/" + v.id + "/review", {
                                action: "suspend",
                                reason,
                              });
                          }}
                        >
                          Suspend
                        </button>
                      )}
                    </div>
                  )}
                </div>
              </section>
            ))
          ) : (
            <Empty />
          )}
        </div>
      ) : table === "budgets" ? (
        <div className="budget-grid standalone">
          {rows.length ? (
            rows.map((b) => {
              const used =
                Number(b.reserved) + Number(b.committed) + Number(b.actual);
              return (
                <section className="panel" key={b.id}>
                  <div className="eyebrow">BUDGET LEDGER</div>
                  <h2>{b.name}</h2>
                  <strong className="large-value">
                    {money(Number(b.allocated) - used, b.currency)}
                  </strong>
                  <small>
                    Available / {money(b.allocated ?? 0, b.currency)}
                  </small>
                  <div className="budget-bar">
                    <span
                      style={{
                        width:
                          Math.min(100, (used / Number(b.allocated)) * 100) +
                          "%",
                      }}
                    />
                  </div>
                  <dl className="budget-breakdown">
                    {["allocated", "reserved", "committed", "actual"].map(
                      (f) => (
                        <div key={f}>
                          <dt>{f}</dt>
                          <dd>
                            {money(
                              (b[f as keyof AdminRow] as string) ?? 0,
                              b.currency,
                            )}
                          </dd>
                        </div>
                      ),
                    )}
                  </dl>
                </section>
              );
            })
          ) : (
            <Empty />
          )}
        </div>
      ) : integration ? (
        <>
          <div className="integration-grid">
            {integration.connectors.map((c) => (
              <section className="panel" key={c.id}>
                <Network size={28} />
                <h2>{c.name}</h2>
                <p>
                  {c.protocol.toUpperCase()} ·{" "}
                  {c.enabled ? "Enabled" : "Disabled"}
                </p>
                <small className="break">
                  {c.endpoint ?? "Sandbox connector"}
                </small>
                <button
                  className="secondary full"
                  onClick={() => action("integrations/" + c.id + "/toggle", {})}
                >
                  {c.enabled ? "Disable" : "Enable"}
                </button>
              </section>
            ))}
          </div>
          <section className="panel">
            <div className="panel-head">
              <h2>Transactional outbox</h2>
              <span>Retries and dead-letter visibility</span>
            </div>
            {integration.outbox.length ? (
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Event</th>
                      <th>Status</th>
                      <th>Attempts</th>
                      <th>Diagnostic</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {integration.outbox.map((e) => (
                      <tr key={e.id}>
                        <td>
                          #{e.id} · {e.type}
                        </td>
                        <td>
                          <Status value={e.status} />
                        </td>
                        <td>{e.attempts}</td>
                        <td>{e.last_error ?? "—"}</td>
                        <td>
                          {e.status === "dead" && (
                            <button
                              className="secondary"
                              onClick={() =>
                                action("outbox/" + e.id + "/replay", {})
                              }
                            >
                              Replay
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <Empty text="No integration events" />
            )}
          </section>
        </>
      ) : table === "policies" ? (
        <div className="policy-grid">
          {rows.length ? (
            rows.map((p) => (
              <section className="panel" key={p.id}>
                <div className="policy-top">
                  <ShieldCheck size={24} />
                  <span>Version {p.version}</span>
                </div>
                <h2>{p.name}</h2>
                <p>
                  {p.kind?.toUpperCase()} ·{" "}
                  {p.active ? "Active" : "Historical policy"}
                </p>
                <p className="muted">
                  Workflow menggunakan snapshot policy saat dokumen diajukan.
                </p>
              </section>
            ))
          ) : (
            <Empty />
          )}
        </div>
      ) : rows.length ? (
        <section className="panel table-wrap">
          <table>
            <thead>
              <tr>
                {(table === "audit"
                  ? ["Event", "Object", "Actor timestamp"]
                  : table === "users"
                    ? ["User", "Role", "Security", "Action"]
                    : ["Code", "Name", "Status", "Action"]
                ).map((x) => (
                  <th key={x}>{x}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((v) => (
                <tr key={v.id}>
                  {table === "audit" ? (
                    <>
                      <td>
                        {v.action}
                        <small className="block muted">Event #{v.id}</small>
                      </td>
                      <td>
                        {v.object_type} #{v.object_id}
                      </td>
                      <td>{date(v.created_at)}</td>
                    </>
                  ) : table === "users" ? (
                    <>
                      <td>
                        <b>{v.name}</b>
                        <small className="block muted">{v.email}</small>
                      </td>
                      <td>{v.role}</td>
                      <td>{v.active ? "Active" : "Disabled"}</td>
                      <td>
                        {v.active && v.id !== me.user.id && (
                          <button
                            className="text-danger"
                            onClick={() => {
                              if (confirm("Nonaktifkan pengguna dan sesi?"))
                                action(
                                  "admin/users/" + v.id + "/deactivate",
                                  {},
                                );
                            }}
                          >
                            Deactivate
                          </button>
                        )}
                      </td>
                    </>
                  ) : (
                    <>
                      <td>{v.code}</td>
                      <td>{v.name}</td>
                      <td>{v.active ? "Active" : "Inactive"}</td>
                      <td>
                        {v.active && (
                          <button
                            className="text-danger"
                            onClick={() => {
                              if (confirm("Nonaktifkan master data ini?"))
                                action(
                                  "admin/" + table + "/" + v.id + "/deactivate",
                                  {},
                                );
                            }}
                          >
                            Deactivate
                          </button>
                        )}
                      </td>
                    </>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      ) : (
        <Empty />
      )}
      {modal && (
        <Modal title={"Add " + title} onClose={() => setModal(false)}>
          <form onSubmit={save}>
            <div className="fields-grid">
              {fields.map((f) => (
                <Field key={f.name} label={f.label}>
                  {f.options ? (
                    <select
                      required={f.required !== false}
                      value={values[f.name] ?? ""}
                      onChange={(e) =>
                        setValues((v) => ({ ...v, [f.name]: e.target.value }))
                      }
                    >
                      <option value="">Choose</option>
                      {f.options.map((o) => (
                        <option
                          key={o.id}
                          value={
                            ["role", "kind", "protocol"].includes(f.name)
                              ? o.code
                              : o.id
                          }
                        >
                          {o.name}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <input
                      required={f.required !== false}
                      type={f.type ?? "text"}
                      min={f.type === "number" ? 1 : undefined}
                      minLength={f.name === "password" ? 14 : undefined}
                      value={values[f.name] ?? ""}
                      onChange={(e) =>
                        setValues((v) => ({ ...v, [f.name]: e.target.value }))
                      }
                    />
                  )}
                </Field>
              ))}
            </div>
            {table === "policies" && (
              <Field label="Parallel approvers in this step">
                <select
                  multiple
                  required
                  value={selected.map(String)}
                  onChange={(e) =>
                    setSelected(
                      Array.from(e.target.selectedOptions, (o) =>
                        Number(o.value),
                      ),
                    )
                  }
                >
                  {look.data?.users
                    .filter((u) =>
                      ["approver", "manager"].includes(u.role ?? ""),
                    )
                    .map((u) => (
                      <option key={u.id} value={u.id}>
                        {u.name}
                      </option>
                    ))}
                </select>
              </Field>
            )}
            <ErrorBox error={error} />
            <button className="primary full" disabled={busy}>
              {busy ? "Saving…" : "Save record"}
            </button>
          </form>
        </Modal>
      )}
    </>
  );
}
function Security() {
  const { me, t } = useContext(Context);
  return <>
    <Heading eyebrow="IDENTITY / ACCESS" title={t("Akses akun Anda.", "Your account access.")}
      description={t("Masuk menggunakan email dan password. Hak akses mengikuti peran dan workspace.", "Sign in with email and password. Permissions follow your role and workspace.")} />
    <section className="panel security-panel">
      <div className="security-icon"><LockKeyhole size={35} /></div>
      <h2>{me.user.name}</h2><p>{me.user.email}</p>
      <p>{me.tenant.name} · {me.membership.role}</p>
      <p>{t("Sesi akan berakhir otomatis setelah batas waktunya tercapai.", "Sessions expire automatically after their time limit.")}</p>
    </section>
  </>;
}

function DataTools() {
  const { me, t } = useContext(Context);
  const [sp] = useSearchParams();
  const [resource, setResource] = useState("legal_entities"),
    [kind, setKind] = useState(sp.get("kind") ?? ""),
    [format, setFormat] = useState("xlsx"),
    [error, setError] = useState<unknown>(),
    [busy, setBusy] = useState(false),
    [preview, setPreview] = useState<{
      id: number;
      total: number;
      invalid: number;
      rows: {
        row: number;
        values: Record<string, string>;
        errors: string[];
        existing: boolean;
      }[];
    } | null>(null),
    [result, setResult] = useState<{
      created: number;
      skipped_existing: number;
    } | null>(null);
  const qc = useQueryClient();
  const q = useQuery({
    queryKey: ["exports"],
    queryFn: () =>
      api<
        {
          id: number;
          format: string;
          status: string;
          error: string | null;
          created_at: string;
        }[]
      >("exports"),
    refetchInterval: 5000,
  });
  const resources = [
    ...(me.permissions.includes("master") ? Object.keys(masterNames) : []),
    ...(me.permissions.includes("vendor.manage") ? ["vendors"] : []),
  ];
  return (
    <>
      <Heading
        eyebrow="DATA OPERATIONS / CONTROLLED TRANSFERS"
        title={t("Impor & ekspor data", "Import & export data")}
        description={t(
          "Validasi sebelum impor. Laporan besar diproses worker tanpa mengunci browser.",
          "Validate before import. Large reports run in a background worker.",
        )}
      />
      <div className="overview-grid">
        <section className="panel">
          <div className="section-title">
            <span>01</span>
            <h2>{t("Ekspor dokumen", "Export documents")}</h2>
          </div>
          <Field label="Document scope">
            <select value={kind} onChange={(e) => setKind(e.target.value)}>
              <option value="">All visible documents</option>
              {Object.entries(labels).map(([k, v]) => (
                <option key={k} value={k}>
                  {t(...v)}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Format">
            <select value={format} onChange={(e) => setFormat(e.target.value)}>
              <option value="xlsx">Excel workbook (.xlsx)</option>
              <option value="csv">CSV (.csv)</option>
            </select>
          </Field>
          <button
            className="primary"
            disabled={busy}
            onClick={async () => {
              setBusy(true);
              setError(undefined);
              try {
                await api("exports", "POST", { format, kind: kind || null });
                qc.invalidateQueries({ queryKey: ["exports"] });
              } catch (e) {
                setError(e);
              } finally {
                setBusy(false);
              }
            }}
          >
            <Download size={16} />
            Generate export
          </button>
          <p className="small muted" style={{ marginTop: 20 }}>
            Only permitted documents are included. Download expires after one
            hour.
          </p>
        </section>
        <section className="panel">
          <div className="panel-head">
            <h2>Export queue</h2>
            <RefreshCw size={17} />
          </div>
          {q.data?.length ? (
            q.data.map((x) => (
              <div className="attachment" key={x.id}>
                <FileText size={18} />
                <span>
                  Export #{x.id} · {x.format.toUpperCase()}
                  <small>{date(x.created_at)}</small>
                  {x.error && <small>{x.error}</small>}
                </span>
                <Status value={x.status} />
                {x.status === "ready" && (
                  <a
                    className="iconbtn"
                    aria-label={"Download export " + x.id}
                    href={"/api/v1/exports/" + x.id + "/download"}
                  >
                    <Download size={18} />
                  </a>
                )}
              </div>
            ))
          ) : (
            <Empty text="No exports yet" />
          )}
        </section>
      </div>
      {resources.length > 0 && (
        <section className="panel">
          <div className="section-title">
            <span>02</span>
            <h2>{t("Impor master data", "Import master data")}</h2>
          </div>
          <div className="import-controls">
            <Field label="Resource">
              <select
                value={resource}
                onChange={(e) => {
                  setResource(e.target.value);
                  setPreview(null);
                  setResult(null);
                }}
              >
                {resources.map((x) => (
                  <option key={x} value={x}>
                    {masterNames[x] ?? "Vendors"}
                  </option>
                ))}
              </select>
            </Field>
            <a
              className="secondary"
              href={"/api/v1/imports/" + resource + "/template"}
            >
              Download CSV template
            </a>
            <label className="secondary upload-label">
              Upload CSV / XLSX
              <input
                type="file"
                accept=".csv,.xlsx"
                disabled={busy}
                onChange={async (e) => {
                  const f = e.target.files?.[0];
                  if (!f) return;
                  setBusy(true);
                  setError(undefined);
                  setResult(null);
                  try {
                    setPreview(
                      await upload("imports/" + resource + "/preview", f),
                    );
                  } catch (e) {
                    setError(e);
                  } finally {
                    setBusy(false);
                    e.target.value = "";
                  }
                }}
              />
            </label>
          </div>
          <p className="small muted">
            Maximum 10,000 rows. Existing codes are skipped; existing data is
            never silently overwritten. Formula cells are rejected.
          </p>
          {preview && (
            <>
              <div className="panel-head">
                <h3>
                  {preview.total} rows · {preview.invalid} invalid
                </h3>
                <button
                  className="primary"
                  disabled={busy || preview.invalid > 0 || !!result}
                  onClick={async () => {
                    setBusy(true);
                    setError(undefined);
                    try {
                      setResult(
                        await api(
                          "imports/" + preview.id + "/commit",
                          "POST",
                          {},
                        ),
                      );
                      qc.invalidateQueries();
                    } catch (e) {
                      setError(e);
                    } finally {
                      setBusy(false);
                    }
                  }}
                >
                  Commit validated import
                </button>
              </div>
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Row</th>
                      <th>Code</th>
                      <th>Name</th>
                      <th>Validation</th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.rows.slice(0, 100).map((x) => (
                      <tr key={x.row}>
                        <td>{x.row}</td>
                        <td>{x.values.code}</td>
                        <td>{x.values.name}</td>
                        <td>
                          {x.errors.length
                            ? x.errors.join("; ")
                            : x.existing
                              ? "Existing — skip"
                              : "Ready to import"}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {preview.total > 100 && (
                <p className="small muted">
                  Preview displays first 100 rows; validation covers all{" "}
                  {preview.total} rows.
                </p>
              )}
            </>
          )}
          {result && (
            <p className="success" role="status">
              Imported {result.created} records. Skipped{" "}
              {result.skipped_existing} existing codes.
            </p>
          )}
        </section>
      )}
      <ErrorBox error={error ?? q.error} />
    </>
  );
}
createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    <QueryClientProvider client={client}>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </QueryClientProvider>
  </React.StrictMode>,
);
