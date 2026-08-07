"""Downloads and stores the original PDF.

The stored file is the source of truth for everything downstream. Extraction
is the part of this pipeline most likely to need fixing — the DOE's layouts
differ between regions and change without notice — and a fix is only useful if
it can be re-run against the documents it would have got wrong. The DOE does
not keep superseded weeks accessible, so a PDF not kept here is gone.

The checksum is of the PDF bytes, and it is what makes the pipeline idempotent:
the same document downloaded twice is the same report, whatever URL or filename
it arrived under.
"""

from __future__ import annotations

import hashlib
import time
from dataclasses import dataclass
from pathlib import Path

import requests
from tenacity import retry, retry_if_exception_type, stop_after_attempt, wait_exponential

from logger import get_logger
from settings import Settings, get_settings

log = get_logger(__name__)

#: The first bytes of any PDF. Checked because the DOE portal answers a missing
#: document with an HTML error page and a 200, which would otherwise be stored
#: as a .pdf and fail much later with an unhelpful extraction error.
_PDF_MAGIC = b'%PDF-'


class DownloadError(RuntimeError):
    """A document could not be retrieved, or was not a PDF."""


@dataclass(frozen=True)
class DownloadedPdf:
    """A stored PDF and its identity."""

    path: Path
    url: str
    filename: str
    checksum: str
    size_bytes: int
    #: False when the checksum matched a file already on disk.
    newly_downloaded: bool


class PdfDownloader:
    def __init__(self, settings: Settings | None = None, session: requests.Session | None = None):
        self.settings = settings or get_settings()
        self.session = session or requests.Session()
        self.session.headers.update({'User-Agent': self.settings.user_agent})
        self.settings.download_dir.mkdir(parents=True, exist_ok=True)

    def fetch(self, url: str, filename: str) -> DownloadedPdf:
        """Download a PDF and store it under its checksum."""
        body = self._get(url)

        if not body.startswith(_PDF_MAGIC):
            # Almost always the portal's HTML error page returned with a 200.
            raise DownloadError(
                f'{url} did not return a PDF (starts with {body[:16]!r})'
            )

        checksum = hashlib.sha256(body).hexdigest()
        path = self._path_for(checksum, filename)

        if path.exists() and path.stat().st_size == len(body):
            log.info('Already stored', extra={'checksum': checksum[:12], 'path': str(path)})

            return DownloadedPdf(
                path=path,
                url=url,
                filename=filename,
                checksum=checksum,
                size_bytes=len(body),
                newly_downloaded=False,
            )

        # Written via a temporary file and moved into place, so a run
        # interrupted mid-write does not leave a truncated PDF that looks
        # stored and extracts to nonsense.
        temporary = path.with_suffix(path.suffix + '.part')
        temporary.write_bytes(body)
        temporary.replace(path)

        log.info(
            'Downloaded %s (%d bytes)',
            filename,
            len(body),
            extra={'checksum': checksum[:12], 'url': url},
        )

        return DownloadedPdf(
            path=path,
            url=url,
            filename=filename,
            checksum=checksum,
            size_bytes=len(body),
            newly_downloaded=True,
        )

    def _path_for(self, checksum: str, filename: str) -> Path:
        """Where a document is stored.

        Sharded by the checksum's first two characters. A flat directory of
        every region's weekly report becomes tens of thousands of files, which
        some filesystems handle poorly and every `ls` handles badly.
        """
        directory = self.settings.download_dir / checksum[:2]
        directory.mkdir(parents=True, exist_ok=True)

        stem = Path(filename).stem[:80]

        return directory / f'{stem}-{checksum[:12]}.pdf'

    @retry(
        retry=retry_if_exception_type(requests.RequestException),
        stop=stop_after_attempt(3),
        wait=wait_exponential(multiplier=2, min=2, max=15),
        reraise=True,
    )
    def _get(self, url: str) -> bytes:
        time.sleep(self.settings.request_delay_s)

        try:
            response = self.session.get(
                url,
                timeout=self.settings.request_timeout_s,
                stream=True,
            )
            response.raise_for_status()
        except requests.RequestException:
            raise

        chunks = bytearray()

        # Streamed with a ceiling rather than read whole: a mis-discovered link
        # to a large document should not be pulled entirely into memory before
        # being rejected.
        for chunk in response.iter_content(chunk_size=65536):
            chunks.extend(chunk)

            if len(chunks) > self.settings.max_pdf_bytes:
                response.close()
                raise DownloadError(
                    f'{url} exceeds {self.settings.max_pdf_bytes} bytes; not a price report'
                )

        return bytes(chunks)

    def prune(self) -> int:
        """Delete PDFs past the retention window. Returns the count.

        Retention defaults to 0, meaning keep everything — see the module
        docstring. This exists for deployments with a real disk constraint.
        """
        days = self.settings.pdf_retention_days

        if days <= 0:
            return 0

        cutoff = time.time() - (days * 86_400)
        removed = 0

        for path in self.settings.download_dir.rglob('*.pdf'):
            if path.stat().st_mtime < cutoff:
                path.unlink()
                removed += 1

        return removed
