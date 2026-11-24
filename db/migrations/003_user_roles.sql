ALTER TABLE users
ADD COLUMN role text NOT NULL DEFAULT 'customer'
    CHECK (role IN ('customer', 'admin'));
