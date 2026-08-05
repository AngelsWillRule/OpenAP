<?php $_SESSION['lastActivity'] = time(); ?>

<div class="d-flex flex-column flex-sm-row align-items-center justify-content-between small">
  <div class="text-muted">
    <span class="pe-2"><a href="/about">v<?php echo OPENAP_VERSION; ?></a></span>  |
    <span class="ps-2"><?php echo sprintf(_('OpenAP fork based on <a href="%s" target="_blank" rel="noopener">%s</a>'), 'https://github.com/RaspAP/raspap-webgui', _('RaspAP')); ?></span>
  </div>
  <div class="text-muted">
    <i class="fas fa-book-reader"></i> <a href="https://angelswillrule.github.io/OpenAP/" target="_blank" rel="noopener"><?php echo _("OpenAP documentation"); ?></a>
  </div>
</div>
