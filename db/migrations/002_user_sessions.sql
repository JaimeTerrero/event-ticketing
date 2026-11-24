CREATE TABLE user_sessions (
    token_hash text PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    expires_at timestamptz NOT NULL
);

CREATE INDEX user_sessions_user_idx ON user_sessions (user_id);
