-- The fedifeeds_item table of sql/tables.json, for a wiki that has fedifeeds_follower already
CREATE TABLE fedifeeds_item (
  fi_id SERIAL NOT NULL,
  fi_feed TEXT NOT NULL,
  fi_page INT NOT NULL,
  fi_timestamp TIMESTAMPTZ NOT NULL,
  PRIMARY KEY(fi_id)
);

CREATE UNIQUE INDEX fi_feed_page ON fedifeeds_item (fi_feed, fi_page);

CREATE INDEX fi_feed_timestamp ON fedifeeds_item (fi_feed, fi_timestamp);
