-- The fedifeeds_quote table of sql/tables.json, for a wiki that has fedifeeds_item already
CREATE TABLE /*_*/fedifeeds_quote (
  fq_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  fq_feed BLOB NOT NULL, fq_item BLOB NOT NULL,
  fq_actor BLOB NOT NULL, fq_post BLOB NOT NULL,
  fq_request BLOB NOT NULL, fq_timestamp BLOB NOT NULL
);

CREATE UNIQUE INDEX fq_post_feed ON /*_*/fedifeeds_quote (fq_post, fq_feed);

CREATE INDEX fq_actor_request ON /*_*/fedifeeds_quote (fq_actor, fq_request);
