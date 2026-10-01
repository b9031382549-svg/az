# syntax=docker/dockerfile:1
# The sorter service (server.py): good / service / trash for an invoice line. The model files
# are mounted at /models/sorter, not baked in. Versions are pinned to the ones the trash
# threshold was calibrated with — another onnxruntime/tokenizers build can move the
# probabilities that threshold was derived from (research-data/sorter-model).
FROM python:3.12-slim

RUN pip install --no-cache-dir onnxruntime==1.30.0 tokenizers==0.22.2 numpy==2.5.1

WORKDIR /app
COPY server.py .

ENV SORTER_MODEL_DIR=/models/sorter \
    SORTER_THREADS=2 \
    SORTER_PORT=8000 \
    PYTHONUNBUFFERED=1

EXPOSE 8000
USER nobody
CMD ["python", "server.py"]
