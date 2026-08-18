FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1
WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends gcc \
 && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install -r requirements.txt

COPY surfscrape/ ./surfscrape/
COPY sites/ ./sites/
COPY scrapy.cfg .

# Scrapy's HTTP cache must survive between daily runs - that is what makes the
# second run cheap (304s instead of full bodies).
ENV SCRAPY_SETTINGS_MODULE=surfscrape.settings
VOLUME ["/data/httpcache", "/data/output"]

ENTRYPOINT ["python", "-m", "surfscrape"]
CMD ["site", "all", "--format", "csv", "--out", "/data/output", "--timestamp"]
