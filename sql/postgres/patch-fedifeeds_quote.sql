-- The fedifeeds_quote table of sql/tables.json, for a wiki that has fedifeeds_item already
CREATE TABLE fedifeeds_quote (
  fq_id SERIAL NOT NULL,
  fq_feed TEXT NOT NULL,
  fq_item TEXT NOT NULL,
  fq_actor TEXT NOT NULL,
  fq_post TEXT NOT NULL,
  fq_request TEXT NOT NULL,
  fq_timestamp TIMESTAMPTZ NOT NULL,
  PRIMARY KEY(fq_id)
);

CREATE UNIQUE INDEX fq_post_feed ON fedifeeds_quote (fq_post, fq_feed);

CREATE INDEX fq_actor_request ON fedifeeds_quote (fq_actor, fq_request);
