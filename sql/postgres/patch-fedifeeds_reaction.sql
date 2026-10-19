-- The fedifeeds_reaction table of sql/tables.json, for a wiki that has fedifeeds_item already
CREATE TABLE fedifeeds_reaction (
  fr_id SERIAL NOT NULL,
  fr_feed TEXT NOT NULL,
  fr_page INT NOT NULL,
  fr_key TEXT DEFAULT '' NOT NULL,
  fr_type TEXT NOT NULL,
  fr_actor TEXT NOT NULL,
  fr_activity TEXT DEFAULT '' NOT NULL,
  fr_timestamp TIMESTAMPTZ NOT NULL,
  PRIMARY KEY(fr_id)
);

CREATE UNIQUE INDEX fr_feed_page_key_type_actor ON fedifeeds_reaction (
  fr_feed, fr_page, fr_key, fr_type,
  fr_actor
);

CREATE INDEX fr_actor_activity ON fedifeeds_reaction (fr_actor, fr_activity);

CREATE INDEX fr_page_type ON fedifeeds_reaction (fr_page, fr_type);
