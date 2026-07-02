# The Agent Brain — a dependency-free image. Runs on the Python standard
# library alone, so the image is tiny and starts instantly.
FROM python:3.11-slim

WORKDIR /app
COPY . /app

# memories persist on a mounted volume so they survive restarts/updates
VOLUME /data
ENV PYTHONUNBUFFERED=1
EXPOSE 8000

# listen on all interfaces so agents on your network/NAS can reach it
CMD ["python", "main.py", "--host", "0.0.0.0", "--port", "8000", "--db", "/data/brain.db"]
