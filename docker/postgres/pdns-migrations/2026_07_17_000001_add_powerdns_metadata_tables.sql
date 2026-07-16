-- PowerDNS runtime schema migration. Safe to apply repeatedly and kept separate
-- from Laravel's control-plane migrations.
CREATE TABLE IF NOT EXISTS supermasters (
  ip INET NOT NULL,
  nameserver VARCHAR(255) NOT NULL,
  account VARCHAR(40) NOT NULL,
  PRIMARY KEY(ip, nameserver)
);

CREATE TABLE IF NOT EXISTS comments (
  id BIGSERIAL PRIMARY KEY,
  domain_id INT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  name VARCHAR(255) NOT NULL,
  type VARCHAR(10) NOT NULL,
  modified_at INT NOT NULL,
  account VARCHAR(40) DEFAULT NULL,
  comment VARCHAR(65535) NOT NULL,
  CONSTRAINT comments_lowercase_name CHECK (name::TEXT = LOWER(name::TEXT))
);
CREATE INDEX IF NOT EXISTS comments_domain_id_idx ON comments(domain_id);
CREATE INDEX IF NOT EXISTS comments_name_type_idx ON comments(name, type);
CREATE INDEX IF NOT EXISTS comments_order_idx ON comments(domain_id, modified_at);

CREATE TABLE IF NOT EXISTS domainmetadata (
  id BIGSERIAL PRIMARY KEY,
  domain_id INT REFERENCES domains(id) ON DELETE CASCADE,
  kind VARCHAR(32),
  content TEXT
);
CREATE INDEX IF NOT EXISTS domainidmetaindex ON domainmetadata(domain_id);

CREATE TABLE IF NOT EXISTS cryptokeys (
  id BIGSERIAL PRIMARY KEY,
  domain_id INT REFERENCES domains(id) ON DELETE CASCADE,
  flags INT NOT NULL,
  active BOOL,
  published BOOL DEFAULT TRUE,
  content TEXT
);
CREATE INDEX IF NOT EXISTS domainidindex ON cryptokeys(domain_id);

CREATE TABLE IF NOT EXISTS tsigkeys (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(255),
  algorithm VARCHAR(50),
  secret VARCHAR(255),
  CONSTRAINT tsigkeys_lowercase_name CHECK (name::TEXT = LOWER(name::TEXT))
);
CREATE UNIQUE INDEX IF NOT EXISTS namealgoindex ON tsigkeys(name, algorithm);
