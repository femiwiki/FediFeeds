-- The fedifeeds_follower table of sql/tables.json, for a wiki that has fedifeeds_key already
CREATE TABLE fedifeeds_follower (
  ff_id SERIAL NOT NULL,
  ff_feed TEXT NOT NULL,
  ff_actor TEXT NOT NULL,
  ff_inbox TEXT NOT NULL,
  ff_timestamp TIMESTAMPTZ NOT NULL,
  PRIMARY KEY(ff_id)
);

CREATE UNIQUE INDEX ff_feed_actor ON fedifeeds_follower (ff_feed, ff_actor);

CREATE INDEX ff_actor ON fedifeeds_follower (ff_actor);
