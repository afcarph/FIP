/** Response shapes mirroring the Laravel API resources. */

export type Role =
  | 'super_admin'
  | 'system_admin'
  | 'station_admin'
  | 'fleet_manager'
  | 'company_manager'
  // Seeded by the backend since the role matrix was written; it was simply
  // never named here, so no screen could gate on it.
  | 'viewer'
  | 'driver'
  | 'user'
  | 'guest';

export type PriceDirection = 'increase' | 'rollback' | 'no_change';
export type PriceSource = 'operator' | 'doe' | 'crowd' | 'ocr' | 'import' | 'ai_estimate';

export interface User {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  initials: string;
  email: string;
  phone: string | null;
  avatar_url: string | null;
  status: 'active' | 'pending' | 'suspended' | 'banned';
  locale: string;
  timezone: string;
  email_verified: boolean;
  mfa_enabled: boolean;
  biometric_enabled: boolean;
  last_login_at: string | null;
  company?: {
    id: number;
    name: string;
    type: string;
    subscription_tier: string;
  } | null;
  preferences?: {
    theme: 'light' | 'dark' | 'system';
    preferred_fuel_type_id: number | null;
    alert_radius_km: number;
    notify_price_alerts: boolean;
    notify_maintenance: boolean;
    notify_ai_insights: boolean;
  };
  roles?: Role[];
}

export interface Session {
  access_token: string;
  token_type: string;
  expires_in: number;
  user: User;
  roles: Role[];
  permissions: string[];
}

export interface MfaChallenge {
  status: 'mfa_required';
  challenge_token: string;
  expires_in: number;
}

export interface FuelType {
  id: number;
  code: string;
  name: string;
  category: 'gasoline' | 'diesel' | 'lpg' | 'ev' | 'cng';
  octane: number | null;
  unit: string;
  color_hex: string;
}

export interface StationPrice {
  fuel_type_id: number;
  fuel_type: string;
  fuel_code: string;
  color_hex: string;
  price: number;
  previous_price: number | null;
  change_amount: number;
  trend: 'up' | 'down' | 'flat';
  source: PriceSource;
  confidence: number;
  is_stale: boolean;
  effective_at: string;
}

export interface Station {
  id: number;
  name: string;
  slug: string;
  brand?: {
    id: number;
    name: string;
    code: string;
    color_hex: string;
    logo_path: string | null;
  };
  address: {
    line: string;
    city?: string;
    region?: string;
    postal_code: string | null;
  };
  latitude: number;
  longitude: number;
  distance_km?: number;
  phone: string | null;
  is_24_hours: boolean;
  has_ev_charging: boolean;
  status: string;
  is_verified: boolean;
  rating: { average: number; count: number };
  prices?: StationPrice[];
  amenities?: Array<{ id: number; code: string; name: string; icon: string | null }>;
  payment_methods?: Array<{ id: number; code: string; name: string; icon: string | null }>;
  photos?: Array<{ id: number; url: string; caption: string | null; is_primary: boolean }>;
  /** Present on the station detail response only. See DoeReference. */
  doe_reference?: DoeReference;
}

/**
 * The DOE's weekly area monitoring, as it relates to one station.
 *
 * `matched: false` means the department publishes nothing that can honestly
 * be attached to this forecourt — the UI must present it as a regional
 * reference and never as this station's price. `reason` says which of the
 * seven conditions failed, so the page can be specific.
 */
export interface DoeReference {
  matched: boolean;
  reason: string;
  /** Only on the unmatched path. Always "DOE Regional Reference". */
  label?: string;
  doe_region?: string;
  doe_area?: string;
  brand?: string;
  station_region?: string;
  station_area?: string;
  station_province?: string;
  doe_province?: string;
  prices: Array<{
    product: string;
    fuel_code: string | null;
    min_price: number | null;
    max_price: number | null;
  }>;
  report?: {
    id: number;
    coverage_start: string;
    coverage_end: string;
    coverage_label: string;
    monitoring_date: string | null;
    source_url: string | null;
  } | null;
  attribution: { source: string; basis: string };
}

export interface CheapestStation {
  id: number;
  name: string;
  brand: string | null;
  address: string;
  latitude: number;
  longitude: number;
  price: number;
  price_effective_at: string | null;
  distance_km: number;
}

/**
 * A fuel anomaly, from GET /fleet/fraud-alerts.
 *
 * One table serves two detectors: alerts raised from a purchase carry
 * `purchase`, alerts raised from a level reading carry `reading`, and neither
 * is guaranteed. `evidence.signals` is what the detector actually measured —
 * shaped per rule, so the UI renders it per rule rather than assuming fields.
 */
export interface AlertSignal {
  type: string;
  weight: number;
  detail: Record<string, string | number | null>;
}

export type AlertStatus = 'open' | 'investigating' | 'confirmed' | 'dismissed';

export interface FleetAlert {
  id: number;
  alert_type: string;
  severity: 'low' | 'medium' | 'high' | 'critical';
  score: number;
  status: AlertStatus;
  detected_at: string;
  resolution_note: string | null;
  resolved_at: string | null;
  vehicle: { id: number; plate_number: string; nickname: string | null } | null;
  driver: { id: number; first_name: string; last_name: string } | null;
  purchase: { id: number; litres: number; total_cost: number; purchased_at: string } | null;
  reading: {
    id: number;
    fuel_pct: number;
    fuel_litres: number | null;
    delta_pct: number | null;
    source: string;
    recorded_at: string;
  } | null;
  evidence: {
    signals?: AlertSignal[];
    source?: string;
    fuel_pct?: number;
    delta_pct?: number;
    recorded_at?: string;
  } | null;
}

/** Tank level bands, computed server-side from config/fip.php thresholds. */
export type FuelStatus = 'NORMAL' | 'LOW' | 'CRITICAL';

export interface Vehicle {
  id: number;
  /** See FleetDriver.company_id. */
  company_id: number | null;
  nickname: string | null;
  display_name: string;
  plate_number: string;
  vehicle_type: string;
  year: number | null;
  color: string | null;
  transmission: string | null;
  make?: string | null;
  model?: string | null;
  fuel_type?: { id: number; name: string; code: string; color_hex: string };
  fleet?: { id: number; name: string } | null;
  tank_capacity: number | null;
  current_odometer: number;
  /**
   * Every field is null until someone records a reading, and `status` is null
   * rather than NORMAL in that case — the API distinguishes "the tank is fine"
   * from "nobody has told us", and the UI has to keep that distinction.
   */
  fuel: {
    current_percentage: number | null;
    current_litres: number | null;
    recorded_at: string | null;
    status: FuelStatus | null;
    is_stale: boolean;
  };
  efficiency: {
    baseline_km_per_litre: number | null;
    avg_km_per_litre: number | null;
    deviation_pct: number | null;
    estimated_range_km: number | null;
  };
  documents: {
    registration_expiry: string | null;
    insurance_provider: string | null;
    insurance_expiry: string | null;
    registration_expires_in_days: number | null;
    insurance_expires_in_days: number | null;
  };
  assigned_driver?: { id: number; name: string } | null;
  maintenance?: Array<{ service: string; status: string; due_at: string | null }>;
  status: string;
  photo_path: string | null;
  created_at?: string;
}

/** GET /vehicles/{id}/efficiency — one point per fill-up, oldest first. */
export interface VehicleEfficiency {
  baseline_km_per_litre: number | null;
  avg_km_per_litre: number | null;
  deviation_pct: number | null;
  estimated_range_km: number | null;
  /**
   * Distance recorded by completed trips over 90 days. Distance only — the API
   * deliberately derives no economy figure from it, because trip coverage is
   * partial and dividing by litres bought gave numbers that read as a failing
   * engine. Fuel economy stays with `avg_km_per_litre`. Null when nothing has
   * been driven.
   */
  from_trips: { distance_km: number; trips: number } | null;
  series: Array<{
    date: string;
    km_per_litre: number | null;
    cost_per_km: number | null;
    price_per_litre: number;
  }>;
}

/** One sample of what the tank held, from GET /vehicles/{id}/fuel-readings. */
export interface FuelReading {
  id: number;
  fuel_pct: number;
  fuel_litres: number | null;
  /** Null on the first reading — an absence, not a zero. */
  delta_pct: number | null;
  source: string;
  recorded_at: string;
  /** Set when a rise is explained by a recorded fill-up. */
  fuel_purchase_id: number | null;
  created_at: string | null;
}

/** The history endpoint, reshaped by the hook into series plus current state. */
export interface FuelReadingHistory {
  readings: FuelReading[];
  current: {
    fuel_pct: number | null;
    fuel_litres: number | null;
    recorded_at: string | null;
    status: FuelStatus | null;
  };
  tank_capacity: number | null;
}

/**
 * POST /expenses/scan-receipt — a draft fill-up read off a photograph.
 *
 * Deliberately not a FuelPurchase: nothing is recorded until the user submits
 * the ordinary expense form, so every field here is a suggestion.
 */
export interface ReceiptScanResult {
  receipt_path: string;
  draft: {
    litres: number | null;
    price_per_litre: number | null;
    total_cost: number | null;
    odometer: number | null;
    purchased_at: string | null;
    fuel_type_id: number | null;
    station_hint: string | null;
    station_id: number | null;
  };
  confidence: number;
  warnings: string[];
  needs_review: boolean;
  station_candidates: Array<{ id: number; name: string; brand: string | null }>;
}

/** POST /vehicles/{id}/fuel-readings — the reading, plus the vehicle it moved. */
export interface FuelReadingResult {
  id: number;
  fuel_pct: number;
  fuel_litres: number | null;
  delta_pct: number | null;
  source: string;
  recorded_at: string;
  vehicle: {
    current_fuel_pct: number | null;
    current_fuel_litres: number | null;
    fuel_level_at: string | null;
    fuel_status: FuelStatus | null;
  };
}

export interface ForecastDriver {
  factor: string;
  weight: number;
  value: string;
  direction?: 'up' | 'down' | 'flat';
}

export interface Forecast {
  id: number;
  fuel_type: { id: number; name: string; code: string; color_hex: string };
  forecast_for: string;
  generated_at: string;
  direction: PriceDirection;
  change_amount: number;
  predicted_price: number | null;
  range: { lower: number | null; upper: number | null };
  confidence: number;
  confidence_label: 'high' | 'moderate' | 'low';
  is_confident: boolean;
  label: string;
  narrative: string | null;
  drivers: ForecastDriver[];
  actual_change: number | null;
  absolute_error: number | null;
  was_correct?: boolean;
}

export interface FuelPurchase {
  id: number;
  purchased_at: string;
  vehicle?: { id: number; plate_number: string; name: string };
  station?: { id: number; name: string; brand: string | null } | null;
  fuel_type?: string;
  litres: number;
  price_per_litre: number;
  total_cost: number;
  odometer: number | null;
  distance_since_last: number | null;
  km_per_litre: number | null;
  cost_per_km: number | null;
  is_full_tank: boolean;
  notes: string | null;
  anomaly_score: number | null;
  is_flagged: boolean;
}

export interface ExpenseSummary {
  period: { from: string; to: string };
  fill_ups: number;
  total_litres: number;
  total_cost: number;
  total_distance_km: number;
  avg_price_per_litre: number | null;
  avg_km_per_litre: number | null;
  avg_cost_per_km: number | null;
}

export interface MonthlyPoint {
  period: string;
  total_cost: number;
  total_litres: number;
  avg_price: number;
  fill_ups: number;
}

export interface SavingsAnalysis {
  actual_spend: number;
  best_case_spend: number;
  potential_savings: number;
  savings_pct: number;
  sample_size: number;
}

export interface PriceComparison {
  fuel_type_id: number;
  fuel_code: string;
  fuel_name: string;
  avg_price: number;
  min_price: number;
  max_price: number;
  spread: number;
  station_count: number;
}

export interface TrendPoint {
  date: string;
  avg_price: number;
  min_price: number;
  max_price: number;
  samples: number;
}

export interface HeatMapCell {
  city_id: number;
  city_name: string;
  region_id: number;
  region_name: string;
  latitude: number;
  longitude: number;
  fuel_type_id: number;
  avg_price: number;
  min_price: number;
  max_price: number;
  station_count: number;
}

export interface RegionalMovement {
  region_id: number;
  region_name: string;
  current_avg: number | null;
  previous_avg: number | null;
  change: number | null;
  change_pct: number | null;
}

export interface Advisory {
  week_start: string;
  effective_at: string;
  direction: PriceDirection;
  change_amount: number;
  notes: string | null;
}

export interface PriceReport {
  id: number;
  report_type: 'price' | 'shortage' | 'closure' | 'long_queue' | 'wrong_info';
  station?: { id: number; name: string; brand: string | null; city: string | null };
  fuel_type?: string;
  price: number | null;
  comment: string | null;
  photo_path: string | null;
  distance_m: number | null;
  trust_score: number;
  status: 'pending' | 'approved' | 'auto_approved' | 'rejected' | 'flagged';
  votes: { up: number; down: number; score: number };
  reporter?: { id: number; name: string } | null;
  rejection_reason: string | null;
  created_at: string;
}

export interface OcrLine {
  label: string | null;
  fuel_type_id: number | null;
  fuel_type_code: string | null;
  price: number | null;
  confidence: number | null;
  valid: boolean;
  rejection_reason: string | null;
  bbox: { x: number; y: number; width: number; height: number } | null;
}

export interface OcrScan {
  id: number;
  status: 'processing' | 'parsed' | 'needs_review' | 'approved' | 'rejected' | 'failed';
  engine: string;
  overall_confidence: number | null;
  image_url: string | null;
  station?: { id: number; name: string } | null;
  lines: OcrLine[];
  raw_text?: string;
  error_message: string | null;
  created_at: string;
}

export interface DashboardData {
  summary: ExpenseSummary;
  savings: SavingsAnalysis;
  monthly_series: MonthlyPoint[];
  vehicles: Array<{
    id: number;
    name: string;
    plate_number: string;
    fuel_type: string | null;
    odometer: number;
    avg_km_per_litre: number | null;
    efficiency_deviation_pct: number | null;
    estimated_range_km: number | null;
  }>;
  forecasts: Array<{
    fuel_type_id: number;
    fuel_type: string;
    direction: PriceDirection;
    change_amount: number;
    confidence: number;
    label: string;
    narrative: string | null;
    effective_week: string;
    drivers: ForecastDriver[];
  }>;
  maintenance_due: Array<{
    vehicle: string;
    service: string;
    status: string;
    due_at: string | null;
  }>;
  unread_notifications: number;
}

export interface FleetDashboard {
  vehicles: {
    total: number;
    active: number;
    in_maintenance: number;
    avg_efficiency: number | null;
    total_odometer: number;
  };
  drivers: { total: number; active: number; licence_expiring_30d: number };
  summary: ExpenseSummary;
  monthly_series: MonthlyPoint[];
  savings: SavingsAnalysis;
  top_consumers: Array<{
    vehicle_id: number;
    plate_number: string;
    nickname: string | null;
    total_cost: number;
    total_litres: number;
    avg_km_per_litre: number | null;
  }>;
  fraud_alerts: {
    open: number;
    critical: number;
    recent: Array<{
      id: number;
      type: string;
      severity: string;
      score: number;
      vehicle: string | null;
      driver: string | null;
      detected_at: string;
    }>;
  };
  maintenance: { overdue: number; due_soon: number };
  utilisation: {
    active_vehicles: number;
    utilised: number;
    idle: number;
    utilisation_pct: number;
  };
}

export interface ExecutiveDashboard {
  platform: {
    users: number;
    active_users_30d: number;
    companies: number;
    vehicles: number;
    stations: number;
    fill_ups_30d: number;
  };
  user_growth: Array<{ period: string; total: number }>;
  price_comparison: PriceComparison[];
  regional_movement: RegionalMovement[];
  national_trend: TrendPoint[];
  forecasts: DashboardData['forecasts'];
  forecast_accuracy: {
    samples: number;
    mae: number | null;
    direction_accuracy: number | null;
  };
  crowd: {
    pending_reports: number;
    approved_30d: number;
    contributors_30d: number;
  };
  aggregate_savings: {
    fill_ups_30d: number;
    total_spend_30d: number;
    avg_price_paid: number | null;
    market_avg_price: number;
    estimated_savings_30d: number;
  };
}

export interface AppNotification {
  id: string;
  type: string;
  category: string;
  title: string;
  body: string;
  data: Record<string, unknown> | null;
  action_url: string | null;
  priority: 'low' | 'normal' | 'high' | 'urgent';
  read_at: string | null;
  created_at: string;
}

export interface RouteOption {
  index: number;
  summary: string;
  polyline: string | null;
  distance_km: number;
  duration_minutes: number;
  has_tolls: boolean;
  toll_cost: number;
  estimated_litres: number | null;
  fuel_cost: number | null;
  total_cost: number;
  refuelling_stop: {
    station_id: number;
    name: string;
    brand: string | null;
    latitude: number;
    longitude: number;
    price: number;
    detour_km: number;
  } | null;
  traffic_level: string | null;
}

export interface AssistantReply {
  session_id: number;
  answer: string;
  suggestions: string[];
  sources: string[];
  latency_ms: number;
}

export interface RefuelRecommendation {
  recommendation: 'refuel_now' | 'wait' | 'neutral' | 'no_strong_signal';
  headline: string;
  confidence: number | null;
  estimated_impact: number | null;
  impact_direction?: string;
  effective_on?: string;
  drivers?: ForecastDriver[];
}

/**
 * A tracking device as a fleet operator sees it.
 *
 * `battery.is_fresh` is not decoration. A device that stopped reporting still
 * holds the last percentage it sent, so rendering `percentage` without checking
 * freshness shows yesterday's charge as today's — and sends somebody looking
 * for a van whose phone is simply flat.
 *
 * `is_online` and `is_tracking` are different questions and both are needed:
 * online means the server has heard from the device, tracking means it is
 * entitled to report positions at all. A revoked handset can be the first
 * without being the second.
 */
export interface DeviceHealth {
  id: number;
  device_name: string | null;
  platform: string;
  app_version: string | null;
  os_version: string | null;
  driver: { id: number; name: string; email: string } | null;
  vehicle: { id: number; plate_number: string; display_name: string | null } | null;
  battery: {
    percentage: number | null;
    state: string | null;
    updated_at: string | null;
    is_fresh: boolean;
    is_charging: boolean;
    is_low: boolean;
  };
  is_online: boolean;
  is_tracking: boolean;
  is_revoked: boolean;
  revoked_at: string | null;
  last_seen_at: string | null;
  /**
   * Absent entirely when the device has never reported a position, or when the
   * caller lacks `devices.location.view` — the same permission that guards
   * `/fleet/locations`. `is_fresh` travels with the coordinate for the reason
   * the battery's does: a position without its age is a guess about where a
   * vehicle is now.
   */
  last_location?: {
    latitude: number;
    longitude: number;
    recorded_at: string;
    is_fresh: boolean;
  } | null;
  registered_at: string | null;
}

/**
 * One vehicle's last known position, from `GET /fleet/locations`.
 *
 * Only vehicles whose device has ever reported appear at all — a vehicle
 * missing from this list has no position, which the fleet map states rather
 * than leaving to be inferred from an empty patch of map.
 *
 * `is_fresh` is the server's judgement, not the client's: the staleness
 * threshold is one setting shared with device health, and a map that decided
 * for itself would disagree with the device page the day it changes.
 */
export interface VehicleLocation {
  vehicle_id: number;
  plate_number: string | null;
  display_name: string | null;
  device_id: number;
  driver_name: string | null;
  latitude: number | null;
  longitude: number | null;
  recorded_at: string | null;
  last_seen_at: string | null;
  is_fresh: boolean;
}

/**
 * One recorded position from a vehicle's track.
 *
 * Both clocks are kept. `recorded_at` is when the device was there;
 * `received_at` is when the server heard about it, which can be hours later if
 * the phone was offline. A track is read on the first; the second is what
 * explains a fix that arrived out of order.
 */
export interface DeviceLocationPoint {
  id: number;
  vehicle_id: number;
  latitude: number;
  longitude: number;
  accuracy_m: number | null;
  altitude_m: number | null;
  speed_kph: number | null;
  heading_deg: number | null;
  recorded_at: string | null;
  received_at: string | null;
}

export interface LocationHistoryLimits {
  max_days: number;
  max_rows: number;
}

/**
 * What a company is using against the plan it is on.
 *
 * `limit: null` means unlimited and stays null rather than becoming a number a
 * client would draw as a cap. `applies: false` is a caller with no company —
 * a private motorist is not a tenant, and zeroes against a plan they are not
 * on would be a lie with a progress bar on it.
 */
/**
 * A plan a prospective client can choose at registration.
 *
 * The limits come from the backend and are rendered as given. A registration
 * page carrying its own numbers would be a second copy of them, and the two
 * would drift the first time the business changed one. There is no price here
 * because none exists yet, and the signup page is the worst possible place to
 * invent one.
 */
export interface Plan {
  name: string;
  label: string;
  description: string;
  /** Offered at registration. `free` is legacy and is not. */
  selectable: boolean;
  /** Negotiated: an administrator confirms the real limits before it applies. */
  requires_confirmation: boolean;
  limits: {
    vehicles: number | null;
    users: number | null;
    devices: number | null;
  };
}

export interface PlanCatalogue {
  plans: Plan[];
  default: string;
  trial_days: number;
  /** The numbers await a business decision and must not be shown as settled. */
  is_provisional: boolean;
}

/**
 * One thing a new company still has to do.
 *
 * Derived server-side from real records rather than a stored checklist, so it
 * cannot congratulate a company for a vehicle it has since deleted. The client
 * renders what it is given and computes no progress of its own.
 */
export interface OnboardingStep {
  key: string;
  title: string;
  description: string;
  href: string;
  action: string;
  done: boolean;
}

export interface OnboardingProgress {
  applies: boolean;
  steps?: OnboardingStep[];
  completed?: number;
  total?: number;
  is_complete?: boolean;
}

export interface SubscriptionUsage {
  used: number;
  limit: number | null;
  remaining: number | null;
  over_limit: boolean;
}

export interface SubscriptionCapacity {
  applies: boolean;
  tier?: string;
  status?: string;
  /** The plan whose numbers are actually applied; differs while pending. */
  effective_tier?: string;
  trial_ends_at?: string | null;
  trial_expired?: boolean;
  has_negotiated_limits?: boolean;
  /** The plan numbers are placeholders awaiting a business decision. */
  is_provisional?: boolean;
  resources?: {
    vehicles: SubscriptionUsage;
    seats: SubscriptionUsage;
    devices: SubscriptionUsage;
  };
}

export type DeviceHealthFilter = 'online' | 'offline' | 'low_battery' | 'charging';

export interface DeviceHealthSummary {
  total: number;
  online: number;
  offline: number;
  low_battery: number;
  charging: number;
}

/** A tenant, as the admin console sees it. */
export interface Company {
  id: number;
  name: string;
  legal_name: string | null;
  tin: string | null;
  industry: string | null;
  type: string | null;
  address_line: string | null;
  city_id: number | null;
  contact_email: string | null;
  contact_phone: string | null;
  subscription_tier: string;
  subscription_status?: string;
  trial_ends_at?: string | null;
  is_active: boolean;
  counts?: { users?: number; vehicles?: number; drivers?: number };
  /** On both the listing and the detail view: the listing counts usage in its
   *  own query so every row can show capacity without a query apiece. */
  subscription?: SubscriptionReport;
  created_at: string | null;
}

/**
 * Usage against a plan.
 *
 * `limit` and `remaining` are null when the tier is unlimited, which is not
 * the same as zero — rendering null as 0 would show an enterprise tenant as
 * permanently full.
 */
export interface SubscriptionReport {
  status?: string;
  /** The plan whose numbers apply; differs while an agreement is unconfirmed. */
  effective_tier?: string;
  trial_ends_at?: string | null;
  trial_expired?: boolean;
  has_negotiated_limits?: boolean;
  tier: string;
  is_provisional: boolean;
  resources: Record<
    'vehicles' | 'seats' | 'devices',
    {
      used: number;
      limit: number | null;
      remaining: number | null;
      over_limit: boolean;
      /** Near the limit but not refused yet — a warning, never enforcement. */
      approaching_limit?: boolean;
    }
  >;
}

/**
 * A driver as DriverResource actually serialises one.
 *
 * Note the shape: the API sends `full_name`, and groups the licence and the
 * scores rather than flattening them. Writing this type from the request
 * payload instead of the response is how a page ends up rendering blank names
 * that still typecheck.
 */
export interface FleetDriver {
  id: number;
  /** Which tenant owns this driver. The assignment screen pairs it against a
   *  vehicle's own company, because the API refuses a cross-company pairing. */
  company_id: number | null;
  employee_no: string | null;
  full_name: string;
  phone: string | null;
  status: string;
  licence: {
    number: string | null;
    type: string | null;
    expiry: string | null;
    /** Negative once expired; null when no expiry is on file. */
    expires_in_days: number | null;
  };
  scores: { safety: number | null; efficiency: number | null };
  fleet?: { id: number; name: string } | null;
  assigned_vehicle?: { id: number; plate_number: string } | null;
  hired_at: string | null;
}

/**
 * The operational block on the fleet dashboard, served under `overview`.
 *
 * Nested rather than spread across the payload's top level because
 * DashboardService::forFleet already publishes `summary`, `vehicles` and
 * `maintenance` meaning entirely different things.
 */
export interface FleetOverview {
  summary: {
    total_vehicles: number;
    available: number;
    on_trip: number;
    maintenance: number;
  };
  vehicles: Array<{
    id: number;
    plate_number: string;
    display_name: string | null;
    driver: { id: number; name: string } | null;
    /** Operational state, not the stored column: available | on_trip | maintenance | inactive. */
    state: 'available' | 'on_trip' | 'maintenance' | 'inactive';
    status: string;
  }>;
  maintenance: Array<{
    id: number;
    vehicle: string | null;
    service: string | null;
    due_at: string | null;
    status: string;
  }>;
  recent_activity: Array<{
    type: string;
    summary: string;
    at: string;
  }>;
}

/**
 * A user as UserResource serialises one — written from the response, not the
 * request payload. `roles` is a flat list of names and `company` is an object
 * or null; the create form's field names are deliberately different and are not
 * interchangeable with these.
 */
export interface AdminUser {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  initials: string;
  email: string;
  phone: string | null;
  status: string;
  email_verified: boolean;
  last_login_at: string | null;
  company: { id: number; name: string; subscription_tier?: string } | null;
  roles: string[];
  created_at: string | null;
}

/** The tiers a company may be put on, as served by the API. */
export interface SubscriptionTiers {
  default: string;
  /** True while the numbers await a business decision. */
  is_provisional: boolean;
  tiers: Array<{
    name: string;
    label: string;
    /** null means unlimited, which is not the same as zero. */
    limits: { vehicles: number | null; seats: number | null; devices: number | null };
  }>;
}

/**
 * A service falling due, as MaintenanceController::due serialises one.
 *
 * `due_at` is a date string, `km_remaining` is null whenever either the
 * schedule has no odometer target or the vehicle has no reading — null means
 * unknown, and rendering it as 0 would read as "due now".
 */
export interface MaintenanceDue {
  id: number;
  vehicle_id: number;
  /** Nickname if the vehicle has one, otherwise the plate. */
  vehicle: string | null;
  service: string | null;
  icon: string | null;
  status: string;
  due_at: string | null;
  km_remaining: number | null;
}

export interface MaintenanceType {
  id: number;
  name: string;
  category: string | null;
}

/**
 * A report the caller is entitled to run.
 *
 * The list is filtered server-side by permission, so anything returned here is
 * runnable — the UI never has to decide who may see what.
 */
export interface ReportDefinition {
  id: number;
  code: string;
  name: string;
  description: string | null;
  scope: string;
  required_permission: string | null;
}

/**
 * One generation of a report.
 *
 * `status` is why this is a record rather than a plain download: anything over
 * the sync row ceiling is queued, so the client gets a `queued` run back and
 * polls until it completes.
 *
 * `download_url` points at the API, not at object storage — the file is
 * streamed behind the bearer token, so fetching it needs `api.download`
 * rather than a plain link.
 */
export interface ReportRun {
  id: number;
  report: string | null;
  code: string | null;
  status: 'queued' | 'running' | 'completed' | 'failed';
  format: string;
  period: { from: string | null; to: string | null };
  row_count: number | null;
  file_size: number | null;
  download_url: string | null;
  error_message: string | null;
  completed_at: string | null;
}

export type TripStatus = 'draft' | 'dispatched' | 'in_progress' | 'completed' | 'cancelled';

/**
 * A planned or completed job.
 *
 * `can` carries the transitions the server will accept from the trip's current
 * state, computed from the same table the API enforces. The screen renders its
 * buttons from it rather than reimplementing the state machine, so it cannot
 * offer a move that would be refused.
 *
 * Assignment and trip are different things: a vehicle can have a driver
 * assigned without being on a trip. Nothing here writes an assignment.
 */
export interface Trip {
  id: number;
  reference_no: string | null;
  status: TripStatus;
  can: TripStatus[];
  vehicle?: { id: number; plate_number: string } | null;
  driver?: { id: number; name: string } | null;
  origin: string | null;
  destination: string | null;
  purpose: string | null;
  notes: string | null;
  odometer: { start: number | null; end: number | null };
  distance_km: number | null;
  timeline: {
    scheduled_for: string | null;
    created_at: string | null;
    dispatched_at: string | null;
    started_at: string | null;
    ended_at: string | null;
    cancelled_at: string | null;
  };
  cancellation_reason: string | null;
  created_by?: string | null;
}

export type TripSummary = Record<TripStatus, number> & { total: number };
