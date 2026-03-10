ALTER TABLE sale_draft_lines ADD COLUMN base_price REAL NOT NULL DEFAULT 0;
ALTER TABLE sale_draft_lines ADD COLUMN override_price REAL NULL;
ALTER TABLE sale_draft_lines ADD COLUMN effective_unit_price REAL NOT NULL DEFAULT 0;
ALTER TABLE sale_draft_lines ADD COLUMN line_subtotal REAL NOT NULL DEFAULT 0;
ALTER TABLE sale_draft_lines ADD COLUMN line_tax REAL NULL;
