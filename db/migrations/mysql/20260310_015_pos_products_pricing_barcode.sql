ALTER TABLE sale_draft_lines
    ADD COLUMN base_price DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER qty,
    ADD COLUMN override_price DECIMAL(18,4) NULL AFTER base_price,
    ADD COLUMN effective_unit_price DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER override_price,
    ADD COLUMN line_subtotal DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER unit_price,
    ADD COLUMN line_tax DECIMAL(18,4) NULL AFTER tax_rate;
