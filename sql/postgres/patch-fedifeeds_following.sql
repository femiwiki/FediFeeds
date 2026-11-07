-- The fedifeeds_following table of sql/tables.json, for a wiki that has fedifeeds_actor already
CREATE TABLE fedifeeds_following (
  fg_id SERIAL NOT NULL,
  fg_feed TEXT NOT NULL,
  fg_account TEXT NOT NULL,
  fg_timestamp TIMESTAMPTZ NOT NULL,
  PRIMARY KEY(fg_id)
);

CREATE UNIQUE INDEX fg_feed_account ON fedifeeds_following (fg_feed, fg_account);
