-- The fedifeeds_actor table of sql/tables.json, for a wiki that has fedifeeds_reaction already
CREATE TABLE fedifeeds_actor (
  fa_id SERIAL NOT NULL,
  fa_actor TEXT NOT NULL,
  fa_handle TEXT DEFAULT '' NOT NULL,
  fa_name TEXT DEFAULT '' NOT NULL,
  fa_url TEXT NOT NULL,
  fa_checked TIMESTAMPTZ NOT NULL,
  PRIMARY KEY(fa_id)
);

CREATE UNIQUE INDEX fa_actor ON fedifeeds_actor (fa_actor);
