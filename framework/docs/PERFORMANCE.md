# PERFORMANCE

## Current state
- Forms load JSON via ContractRepository with cache (APCu/file).
- Grid config parsed at runtime.
- ETag + Cache-Control for /api/contracts/forms/* (MVP).
- Assets cache headers (ETag + Cache-Control) via assets.php.

## Optimization plan (incremental)
1) Contract segmentation
- /contracts/app/{id}/forms/{name}
- /contracts/app/{id}/grids/{name}

2) Cache + ETag (MVP DONE)
- Server cache for parsed contracts (APCu + file fallback).
- ETag/If-None-Match to avoid re-download.

3) Lazy load
- Load grids/forms only on demand.

4) Grid virtualization
- Paginate or virtualize large grids.

5) Static assets
- Enable gzip/brotli, cache-control, and file versioning.

## Metrics
- TTFB, payload size, time to interactive.
