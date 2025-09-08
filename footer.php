
</main>

<footer class="site-footer">
	<div class="site-footer__inner">
		<div class="site-footer__left">&copy; <?php echo date('Y'); ?> Національна академія СБУ</div>
		<div class="site-footer__right">Підтримка: <a href="tel:+380445277690">527-76-90</a></div>
	</div>
</footer>

<?php if (isset($isAdminPage) && $isAdminPage): ?>
<script src="/assets/js/admin.js"></script>
<?php endif; ?>
</body>
</html>
