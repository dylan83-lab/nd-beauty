-- ND Japanese Head Spa — skema database (MySQL/MariaDB & SQLite)
-- Token {PK} diganti otomatis oleh installer. Waktu disimpan sebagai teks WIB: tanggal 'YYYY-MM-DD', waktu 'YYYY-MM-DD HH:MM:SS'.

CREATE TABLE settings (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
);

CREATE TABLE revs (
  id INT NOT NULL PRIMARY KEY,
  n INT NOT NULL DEFAULT 0
);

CREATE TABLE day_locks (
  d CHAR(10) NOT NULL PRIMARY KEY
);

CREATE TABLE users (
  id {PK},
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NULL,
  phone VARCHAR(30) NULL,
  pass_hash VARCHAR(255) NOT NULL,
  role VARCHAR(12) NOT NULL,
  position VARCHAR(60) NOT NULL DEFAULT '',
  active INT NOT NULL DEFAULT 1,
  created_at VARCHAR(19) NOT NULL
);
CREATE UNIQUE INDEX ux_users_email ON users(email);
CREATE UNIQUE INDEX ux_users_phone ON users(phone);

CREATE TABLE login_attempts (
  id {PK},
  ident VARCHAR(160) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  at VARCHAR(19) NOT NULL
);
CREATE INDEX ix_login_ident ON login_attempts(ident, at);

CREATE TABLE customers (
  id {PK},
  user_id INT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(160) NULL,
  notes VARCHAR(255) NOT NULL DEFAULT '',
  plan_id INT NULL,
  member_until CHAR(10) NULL,
  points INT NOT NULL DEFAULT 0,
  created_at VARCHAR(19) NOT NULL
);
CREATE UNIQUE INDEX ux_customers_user ON customers(user_id);
CREATE UNIQUE INDEX ux_customers_phone ON customers(phone);

CREATE TABLE membership_plans (
  id {PK},
  name VARCHAR(80) NOT NULL,
  discount_pct INT NOT NULL DEFAULT 0,
  points_per_10k INT NOT NULL DEFAULT 0,
  price INT NOT NULL DEFAULT 0,
  duration_days INT NOT NULL DEFAULT 365,
  combine_promo INT NOT NULL DEFAULT 0,
  benefits VARCHAR(255) NOT NULL DEFAULT '',
  active INT NOT NULL DEFAULT 1
);

CREATE TABLE loyalty_moves (
  id {PK},
  customer_id INT NOT NULL,
  points INT NOT NULL,
  note VARCHAR(160) NOT NULL DEFAULT '',
  uniq_key VARCHAR(60) NOT NULL,
  created_at VARCHAR(19) NOT NULL
);
CREATE UNIQUE INDEX ux_loyalty_key ON loyalty_moves(uniq_key);

CREATE TABLE services (
  id {PK},
  category VARCHAR(40) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  price INT NOT NULL DEFAULT 0,
  price_from INT NOT NULL DEFAULT 0,
  duration_min INT NOT NULL DEFAULT 0,
  resource_type VARCHAR(10) NOT NULL DEFAULT 'general',
  photo VARCHAR(200) NOT NULL DEFAULT '',
  meta TEXT NULL,
  sort INT NOT NULL DEFAULT 0,
  active INT NOT NULL DEFAULT 1
);

CREATE TABLE service_steps (
  id {PK},
  service_id INT NOT NULL,
  step_no INT NOT NULL,
  title VARCHAR(120) NOT NULL,
  detail VARCHAR(255) NOT NULL DEFAULT ''
);
CREATE INDEX ix_steps_service ON service_steps(service_id, step_no);

CREATE TABLE service_materials (
  service_id INT NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(10,2) NOT NULL DEFAULT 1,
  PRIMARY KEY (service_id, product_id)
);

CREATE TABLE therapist_services (
  user_id INT NOT NULL,
  service_id INT NOT NULL,
  PRIMARY KEY (user_id, service_id)
);

CREATE TABLE promos (
  id {PK},
  name VARCHAR(120) NOT NULL,
  starts CHAR(10) NOT NULL,
  ends CHAR(10) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'draft',
  note VARCHAR(255) NOT NULL DEFAULT ''
);

CREATE TABLE promo_items (
  id {PK},
  promo_id INT NOT NULL,
  label VARCHAR(120) NOT NULL,
  info VARCHAR(255) NOT NULL DEFAULT '',
  promo_price INT NOT NULL,
  normal_price INT NOT NULL DEFAULT 0,
  service_id INT NULL,
  includes VARCHAR(120) NOT NULL DEFAULT '',
  duration_min INT NOT NULL DEFAULT 0,
  is_package INT NOT NULL DEFAULT 0
);
CREATE INDEX ix_promo_items ON promo_items(promo_id);

CREATE TABLE resources (
  id {PK},
  name VARCHAR(60) NOT NULL,
  type VARCHAR(10) NOT NULL,
  active INT NOT NULL DEFAULT 1
);

CREATE TABLE shifts (
  id {PK},
  name VARCHAR(40) NOT NULL,
  start_time CHAR(5) NOT NULL,
  end_time CHAR(5) NOT NULL,
  break_start CHAR(5) NULL,
  break_end CHAR(5) NULL,
  late_tol INT NOT NULL DEFAULT 10,
  early_tol INT NOT NULL DEFAULT 10,
  active INT NOT NULL DEFAULT 1
);

CREATE TABLE therapist_pattern (
  user_id INT NOT NULL,
  weekday INT NOT NULL,
  shift_id INT NOT NULL,
  PRIMARY KEY (user_id, weekday)
);

CREATE TABLE schedules (
  user_id INT NOT NULL,
  sched_date CHAR(10) NOT NULL,
  shift_id INT NOT NULL,
  PRIMARY KEY (user_id, sched_date)
);

CREATE TABLE holidays (
  hdate CHAR(10) NOT NULL PRIMARY KEY,
  note VARCHAR(120) NOT NULL DEFAULT ''
);

CREATE TABLE bookings (
  id {PK},
  code VARCHAR(12) NOT NULL,
  customer_id INT NOT NULL,
  source VARCHAR(10) NOT NULL DEFAULT 'online',
  book_date CHAR(10) NOT NULL,
  start_at VARCHAR(19) NOT NULL,
  end_at VARCHAR(19) NOT NULL,
  therapist_id INT NULL,
  therapist_chosen INT NOT NULL DEFAULT 0,
  resource_id INT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'booked',
  pay_status VARCHAR(12) NOT NULL DEFAULT 'unpaid',
  total INT NOT NULL DEFAULT 0,
  notes VARCHAR(255) NOT NULL DEFAULT '',
  cancel_reason VARCHAR(160) NOT NULL DEFAULT '',
  created_by INT NULL,
  created_at VARCHAR(19) NOT NULL,
  checkin_at VARCHAR(19) NULL,
  started_at VARCHAR(19) NULL,
  finished_at VARCHAR(19) NULL,
  cancelled_at VARCHAR(19) NULL
);
CREATE UNIQUE INDEX ux_bookings_code ON bookings(code);
CREATE INDEX ix_bookings_date ON bookings(book_date, status);
CREATE INDEX ix_bookings_cust ON bookings(customer_id);
CREATE INDEX ix_bookings_ther ON bookings(therapist_id, book_date);

CREATE TABLE booking_items (
  id {PK},
  booking_id INT NOT NULL,
  service_id INT NULL,
  promo_item_id INT NULL,
  name VARCHAR(160) NOT NULL,
  price INT NOT NULL,
  duration_min INT NOT NULL,
  svc_ids VARCHAR(120) NOT NULL DEFAULT ''
);
CREATE INDEX ix_bitems_booking ON booking_items(booking_id);

CREATE TABLE products (
  id {PK},
  name VARCHAR(120) NOT NULL,
  kind VARCHAR(6) NOT NULL DEFAULT 'jual',
  unit VARCHAR(16) NOT NULL DEFAULT 'pcs',
  price INT NOT NULL DEFAULT 0,
  cost INT NOT NULL DEFAULT 0,
  min_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
  active INT NOT NULL DEFAULT 1
);

CREATE TABLE stock_moves (
  id {PK},
  product_id INT NOT NULL,
  move_type VARCHAR(10) NOT NULL,
  qty DECIMAL(10,2) NOT NULL,
  unit_cost INT NOT NULL DEFAULT 0,
  note VARCHAR(160) NOT NULL DEFAULT '',
  ref_type VARCHAR(12) NOT NULL DEFAULT '',
  ref_id INT NOT NULL DEFAULT 0,
  uniq_key VARCHAR(60) NULL,
  user_id INT NULL,
  created_at VARCHAR(19) NOT NULL
);
CREATE INDEX ix_stock_product ON stock_moves(product_id);
CREATE UNIQUE INDEX ux_stock_key ON stock_moves(uniq_key);

CREATE TABLE accounts (
  id {PK},
  name VARCHAR(80) NOT NULL,
  type VARCHAR(8) NOT NULL,
  note VARCHAR(160) NOT NULL DEFAULT '',
  active INT NOT NULL DEFAULT 1
);

CREATE TABLE cash_sessions (
  id {PK},
  user_id INT NOT NULL,
  opened_at VARCHAR(19) NOT NULL,
  opening_cash INT NOT NULL DEFAULT 0,
  closed_at VARCHAR(19) NULL,
  expected_cash INT NULL,
  counted_cash INT NULL,
  diff INT NULL,
  diff_note VARCHAR(255) NOT NULL DEFAULT '',
  review_note VARCHAR(255) NOT NULL DEFAULT '',
  reviewed_by INT NULL,
  status VARCHAR(8) NOT NULL DEFAULT 'open'
);
CREATE INDEX ix_cs_user ON cash_sessions(user_id, status);

CREATE TABLE cash_movements (
  id {PK},
  mv_date CHAR(10) NOT NULL,
  account_id INT NOT NULL,
  kind VARCHAR(3) NOT NULL,
  category VARCHAR(20) NOT NULL,
  amount INT NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  ref_type VARCHAR(12) NOT NULL DEFAULT '',
  ref_id INT NOT NULL DEFAULT 0,
  group_id VARCHAR(20) NULL,
  cash_session_id INT NULL,
  user_id INT NULL,
  uniq_key VARCHAR(60) NULL,
  created_at VARCHAR(19) NOT NULL
);
CREATE INDEX ix_cm_account ON cash_movements(account_id, mv_date);
CREATE INDEX ix_cm_date ON cash_movements(mv_date);
CREATE UNIQUE INDEX ux_cm_key ON cash_movements(uniq_key);

CREATE TABLE sales (
  id {PK},
  booking_id INT NULL,
  customer_id INT NULL,
  cashier_id INT NULL,
  cash_session_id INT NULL,
  status VARCHAR(14) NOT NULL DEFAULT 'open',
  receipt_no VARCHAR(24) NULL,
  subtotal INT NOT NULL DEFAULT 0,
  disc_member INT NOT NULL DEFAULT 0,
  disc_manual INT NOT NULL DEFAULT 0,
  manual_reason VARCHAR(120) NOT NULL DEFAULT '',
  points_used INT NOT NULL DEFAULT 0,
  disc_points INT NOT NULL DEFAULT 0,
  total INT NOT NULL DEFAULT 0,
  method VARCHAR(10) NULL,
  account_id INT NULL,
  cash_received INT NULL,
  change_amt INT NULL,
  pay_ref VARCHAR(80) NOT NULL DEFAULT '',
  points_earned INT NOT NULL DEFAULT 0,
  idem_key VARCHAR(60) NULL,
  created_at VARCHAR(19) NOT NULL,
  paid_at VARCHAR(19) NULL
);
CREATE UNIQUE INDEX ux_sales_booking ON sales(booking_id);
CREATE UNIQUE INDEX ux_sales_receipt ON sales(receipt_no);
CREATE UNIQUE INDEX ux_sales_idem ON sales(idem_key);
CREATE INDEX ix_sales_paid ON sales(paid_at);

CREATE TABLE sale_items (
  id {PK},
  sale_id INT NOT NULL,
  item_type VARCHAR(8) NOT NULL,
  ref_id INT NULL,
  promo_item_id INT NULL,
  name VARCHAR(160) NOT NULL,
  price INT NOT NULL,
  qty INT NOT NULL DEFAULT 1,
  disc INT NOT NULL DEFAULT 0,
  net INT NOT NULL DEFAULT 0,
  locked INT NOT NULL DEFAULT 0,
  refunded_qty INT NOT NULL DEFAULT 0,
  refunded_amt INT NOT NULL DEFAULT 0
);
CREATE INDEX ix_sitems_sale ON sale_items(sale_id);

CREATE TABLE refunds (
  id {PK},
  sale_id INT NOT NULL,
  amount INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  request_id INT NULL,
  approved_by INT NULL,
  created_by INT NOT NULL,
  pts_reversed INT NOT NULL DEFAULT 0,
  pts_returned INT NOT NULL DEFAULT 0,
  created_at VARCHAR(19) NOT NULL
);
CREATE INDEX ix_refunds_sale ON refunds(sale_id);

CREATE TABLE refund_items (
  id {PK},
  refund_id INT NOT NULL,
  sale_item_id INT NOT NULL,
  qty INT NOT NULL,
  amount INT NOT NULL
);
CREATE INDEX ix_ritems_refund ON refund_items(refund_id);

CREATE TABLE refund_requests (
  id {PK},
  sale_id INT NOT NULL,
  items_json TEXT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  requested_by INT NOT NULL,
  processed_by INT NULL,
  processed_at VARCHAR(19) NULL,
  process_note VARCHAR(255) NOT NULL DEFAULT '',
  amount INT NOT NULL DEFAULT 0,
  created_at VARCHAR(19) NOT NULL
);
CREATE INDEX ix_rr_status ON refund_requests(status);

CREATE TABLE attendance (
  id {PK},
  user_id INT NOT NULL,
  att_date CHAR(10) NOT NULL,
  shift_id INT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'hadir',
  in_at VARCHAR(19) NULL,
  in_lat DECIMAL(10,7) NULL,
  in_lng DECIMAL(10,7) NULL,
  in_dist INT NULL,
  in_acc INT NULL,
  out_at VARCHAR(19) NULL,
  out_lat DECIMAL(10,7) NULL,
  out_lng DECIMAL(10,7) NULL,
  out_dist INT NULL,
  out_acc INT NULL,
  late_min INT NOT NULL DEFAULT 0,
  early_min INT NOT NULL DEFAULT 0,
  work_min INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX ux_att_user_date ON attendance(user_id, att_date);

CREATE TABLE att_corrections (
  id {PK},
  user_id INT NOT NULL,
  att_date CHAR(10) NOT NULL,
  ctype VARCHAR(8) NOT NULL,
  req_in VARCHAR(19) NULL,
  req_out VARCHAR(19) NULL,
  req_status VARCHAR(10) NULL,
  reason VARCHAR(255) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  original_json TEXT NULL,
  result_json TEXT NULL,
  processed_by INT NULL,
  processed_at VARCHAR(19) NULL,
  process_note VARCHAR(255) NOT NULL DEFAULT '',
  created_at VARCHAR(19) NOT NULL
);
CREATE INDEX ix_corr_status ON att_corrections(status);

CREATE TABLE audit_log (
  id {PK},
  user_id INT NULL,
  action VARCHAR(40) NOT NULL,
  entity VARCHAR(30) NOT NULL DEFAULT '',
  entity_id INT NOT NULL DEFAULT 0,
  detail VARCHAR(500) NOT NULL DEFAULT '',
  created_at VARCHAR(19) NOT NULL
);
CREATE INDEX ix_audit_entity ON audit_log(entity, entity_id);
