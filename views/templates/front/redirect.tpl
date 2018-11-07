<script>
    var mondido_redirect = "{$goto}";
    var isInIframe = (window.location != window.parent.location) ? true : false;
    if (isInIframe == true) {
        window.top.location.href = mondido_redirect;
    } else {
        window.location.href = mondido_redirect;
    }
</script>

<style type="text/css">
    .mondido-overlay {
        position:         fixed;
        z-index:          1000;
        top:              0;
        left:             0;
        height:           100%;
        width:            100%;
        background-color: #e8e8e8;
    }

    .mondido-overlay img {
        position:  fixed;
        top:       50%;
        left:      50%;
        transform: translate(-50%, -50%);
    }

</style>
<!-- <strong>{l s='Please wait...' mod='mondidocheckout'}</strong> -->
<div class="mondido-overlay">
    <img src="{$this_path}views/images/ring-alt.gif">
</div>
