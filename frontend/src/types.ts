export type Kind =
  | "pr"
  | "tender"
  | "contract"
  | "po"
  | "receipt"
  | "return"
  | "invoice"
  | "credit";
export type Line = {
  id?: number;
  source_line_id?: number;
  description: string;
  uom: string;
  quantity: string;
  unit_price: string;
  discount_percent: string;
  tax_percent: string;
  total?: string;
  net?: string;
  tax?: string;
};
export type BusinessData = {
  justification?: string;
  method?: string;
  envelopes?: number;
  criteria?: { name: string; weight: number }[];
  evaluator_ids?: number[];
  opening_user_ids?: number[];
  opening_quorum?: number;
  invoice_number?: string;
  invoice_date?: string;
  due_date?: string;
  payment_terms?: string;
  ends_at?: string;
  starts_at?: string;
  delivery_address?: string;
  payment_reference?: string;
  match_issues?: { code: string; line_id: number; field?: string }[];
  award_reason?: string;
  awarded_vendor_ids?: number[];
  cancellation_reason?: string;
};
export type Doc = {
  id: number;
  kind: Kind;
  number: string;
  title: string;
  status: string;
  total: string;
  currency: string;
  exchange_rate: string;
  budget_value: string;
  legal_entity_id: number;
  cost_center_id: number;
  vendor_id: number | null;
  budget_id: number | null;
  source_id: number | null;
  created_by: number;
  version: number;
  needed_at: string | null;
  deadline: string | null;
  created_at: string;
  data: BusinessData;
  lines?: Line[];
};
export type Lookup = {
  id: number;
  name: string;
  code?: string;
  status?: string;
  role?: string;
  legal_entity_id?: number;
  cost_center_id?: number;
  allocated?: string;
  reserved?: string;
  committed?: string;
  actual?: string;
  currency?: string;
  year?: number;
};
export type Lookups = Record<string, Lookup[]>;
export type Me = {
  user: { id: number; name: string; email: string; mfa_enabled: boolean };
  membership: { tenant_id: number; role: string; vendor_id: number | null };
  tenant: { name: string };
  memberships: { tenant_id: number; name: string; role: string }[];
  permissions: string[];
  production: boolean;
};
export type Page<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};
export type Detail = {
  document: Doc;
  approvals: {
    id: number;
    round: number;
    position: number;
    status: string;
    approver_name: string;
    reason?: string;
    acted_at?: string;
  }[];
  attachments: { id: number; name: string; status: string; size: number }[];
  revisions: {
    id: number;
    version: number;
    reason: string;
    created_at: string;
  }[];
};
export type Dashboard = {
  counts: { kind: Kind; status: string; count: number }[];
  recent: Doc[];
  pending: Doc[];
  budgets: Lookup[];
  notifications: { id: number; title: string }[];
};
export type Bid = {
  id: number;
  vendor_id: number;
  revision: number;
  hash: string;
  submitted_at: string;
  technical: { summary: string } | null;
  commercial: {
    lines: { source_line_id: number; quantity: string; unit_price: string }[];
    currency: string;
    lead_days: number;
    payment_terms: string;
  } | null;
  sealed: boolean;
};
