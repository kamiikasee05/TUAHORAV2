export interface Service {
  id: number;
  name: string;
  duration: number;
  price: number;
  currency: string;
  description: string;
  slotInterval: number;
  attendantsNumber: number;
  serviceCategoryId: number | null;
}

export interface ServiceRow {
  id: number;
  name: string;
  duration: number;
  price: number;
  currency: string;
  description: string;
  slot_interval: number;
  attendants_number: number;
  category_id: number | null;
}

export interface Customer {
  id: number;
  firstName: string;
  lastName: string;
  email: string;
  phone: string;
}

export interface CustomerRow {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  created_at: string;
}

export interface Appointment {
  id: number;
  start: string;
  end: string;
  serviceId: number;
  customerId: number;
  providerId: number;
  status: string;
  notes: string;
  hash: string;
  customer?: Customer;
  service?: ServiceBrief;
  provider?: ProviderBrief;
  oldStart?: string;
  newStart?: string;
  payment?: PaymentBrief;
}

export interface ServiceBrief {
  id: number;
  name: string;
  duration: number;
  price: number;
}

export interface ProviderBrief {
  id: number;
  firstName: string;
  lastName: string;
  address: string;
  profesional: string;
}

export interface AppointmentRow {
  id: number;
  start: string;
  end: string;
  service_id: number;
  customer_id: number;
  provider_id: number;
  status: string;
  notes: string;
  hash: string;
  created_at: string;
  c_first_name?: string;
  c_last_name?: string;
  c_email?: string;
  c_phone?: string;
  s_name?: string;
  s_duration?: number;
  s_price?: number;
  p_first_name?: string;
  p_last_name?: string;
  p_address?: string;
  p_profesional?: string;
  pm_id?: number;
  pm_status?: string;
  pm_method?: string;
  pm_amount?: number;
  pm_paid_at?: string;
}

export interface PaymentRow {
  id: number;
  appointment_id: number;
  amount: number;
  status: string;
  method: string;
  paid_at: string;
  notes: string;
  created_at: string;
}

export interface PaymentBrief {
  id: number;
  appointmentId: number;
  amount: number;
  status: string;
  method: string;
  paidAt: string;
}

export interface ProviderSettingsRow {
  id: number;
  provider_id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  address: string;
  profesional: string;
  timezone: string;
  working_plan: string;
  username: string;
  notifications: number;
  calendar_view: string;
}
