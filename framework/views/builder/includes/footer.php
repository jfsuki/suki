<!-- framework/views/builder/includes/footer.php -->
<footer style="height: 32px; border-top: 1px solid var(--border); background: var(--surface2); display: flex; align-items: center; justify-content: space-between; padding: 0 20px; flex-shrink: 0; font-size: 10px; color: var(--muted); letter-spacing: 0.2px;">
    <div style="display: flex; gap: 15px;">
        <span><strong>Neural Tower</strong> v4.1 Expert</span>
        <span><strong>Project:</strong> <span id="footerProjId">Default</span></span>
        <span><strong>Session:</strong> Active</span>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <div style="width: 6px; height: 6px; border-radius: 50%; background: var(--success); box-shadow: 0 0 6px var(--success);"></div>
        <span>Architect OS Sync</span>
        <span style="opacity: 0.5;">© 2026 SUKI AI-AOS</span>
    </div>
</footer>

<script>
    // System-wide Builder Footer Script
    (function() {
        // Auto-detect project from cookie if present
        const m = document.cookie.match(new RegExp('(?:^|;)\\s*project_id=([^;]*)'));
        const pid = m ? decodeURIComponent(m[1]) : 'default';
        const el = document.getElementById('footerProjId');
        if (el) el.textContent = pid.toUpperCase();
    })();
</script>
</body>
</html>
