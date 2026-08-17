FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1
WORKDIR /app

COPY requirements.txt .
RUN pip install -r requirements.txt

COPY surfscrape/ ./surfscrape/
COPY sites/ ./sites/

# Cache and output are volumes: the conditional-GET cache must survive
# between daily runs, that is what makes the second run cheap.
VOLUME ["/data/cache", "/data/output"]

ENTRYPOINT ["python", "-m", "surfscrape"]
CMD ["scrape", "sites/", "--out", "/data/output", "--cache-dir", "/data/cache", \
     "--format", "csv", "xml", "--timestamp"]
