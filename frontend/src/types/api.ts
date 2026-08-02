/** Response shapes mirroring the Laravel API resources. */

export type Role =
  | 'super_admin'
  | 'system_admin'
  | 'station_admin'
  | 'fleet_manager'
  | 'company_manager'
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

export interface Vehicle {
  id: number;
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
