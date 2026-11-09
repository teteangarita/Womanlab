ALTER TABLE ordenes
  ADD COLUMN tuu_callback_signature CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER tuu_transaction_id;