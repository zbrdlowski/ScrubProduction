ALTER TABLE orders
  ADD COLUMN customs_identifier VARCHAR(128) NULL
  COMMENT 'Customer customs/tax identifier required by selected destination countries'
  AFTER shipping_method;
