BEGIN;

CREATE TABLE users (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL CHECK (btrim(name) <> ''),
    email text NOT NULL UNIQUE CHECK (email = lower(btrim(email)) AND email <> ''),
    password_hash text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE events (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL CHECK (btrim(name) <> ''),
    description text NOT NULL CHECK (btrim(description) <> ''),
    event_date timestamptz NOT NULL,
    total_tickets integer NOT NULL CHECK (total_tickets > 0),
    available_tickets integer NOT NULL
        CHECK (available_tickets BETWEEN 0 AND total_tickets),
    ticket_price numeric(10, 2) NOT NULL CHECK (ticket_price > 0),
    status text NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'published', 'cancelled'))
);

CREATE TABLE ticket_sales (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES users (id),
    event_id bigint NOT NULL REFERENCES events (id),
    quantity integer NOT NULL CHECK (quantity > 0),
    total_amount numeric(20, 2) NOT NULL CHECK (total_amount > 0),
    purchase_date timestamptz NOT NULL DEFAULT now(),
    status text NOT NULL DEFAULT 'confirmed' CHECK (status = 'confirmed')
);

CREATE INDEX ticket_sales_user_history_idx
    ON ticket_sales (user_id, purchase_date DESC, id DESC);

CREATE INDEX ticket_sales_event_idx ON ticket_sales (event_id);
CREATE INDEX events_status_date_idx ON events (status, event_date);

COMMIT;
