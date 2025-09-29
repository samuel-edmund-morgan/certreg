
</main>

<footer class="site-footer">
	<div class="site-footer__inner">
		<?php
			$year = date('Y');
			$footerText = $cfg['footer_text'] ?? '';
			$supportContact = $cfg['support_contact'] ?? '';
			if($footerText===''){ $footerText = "Назва Організації"; }
			// Auto prepend © YEAR if user omitted
			$displayFooter = (preg_match('/^©/u',$footerText) ? $footerText : '© '.$year.' '.$footerText);
			$displayFooter = htmlspecialchars($displayFooter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$supportDisplay = htmlspecialchars($supportContact, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			// Try to detect phone pattern for tel: link
			$telHref = '';
					if($supportContact){
						if(preg_match('/^[+0-9][0-9\-\s()]{4,}$/u',$supportContact)){
							$raw = preg_replace('/[^0-9+]/','',$supportContact);
							// If already starts with +, leave as is
							if(str_starts_with($raw,'+')){
								$digits = $raw;
							} else {
								// Heuristics:
								//  - If length 7 (e.g. 5277690) assume local Kyiv number -> +38044 + number
								//  - If length 9 (e.g. 5277690 with dash removed but actually 44 missing) treat as missing city code? Hard; keep as is.
								//  - If starts with '0' and length 10 (0XXYYYYYYY) -> replace leading 0 with +38
								$len = strlen($raw);
								if($len===7){
									$digits = '+38044'.$raw; // default to Kyiv city code 44
								} elseif($len===10 && $raw[0]==='0'){
									$digits = '+38'.substr($raw,1);
								} elseif($len===9){
									// treat as city code missing leading 0? fallback: assume Kyiv (44) + number after first 2 as number? ambiguous; keep original
									$digits = '+380'.$raw; // assume already without leading 0
								} else {
									$digits = $raw;
								}
							}
							$telHref = 'tel:'.$digits;
						} elseif (filter_var($supportContact, FILTER_VALIDATE_EMAIL)) {
							$telHref = 'mailto:'.$supportContact;
						}
					}
		?>
		<div class="site-footer__left"><?= $displayFooter ?></div>
		<div class="site-footer__right">
			<?php if($supportContact): ?>
				Підтримка:
				<?php if($telHref): ?>
					<a href="<?= htmlspecialchars($telHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= $supportDisplay ?></a>
				<?php else: ?>
					<span><?= $supportDisplay ?></span>
				<?php endif; ?>
			<?php else: ?>
				Підтримка: <a href="tel:+380444444444">444-44-44</a>
			<?php endif; ?>
		</div>
	</div>
</footer>

<?php if (isset($isAdminPage) && $isAdminPage): ?>
<script src="/assets/js/admin.js"></script>
<?php endif; ?>
</body>
</html>
