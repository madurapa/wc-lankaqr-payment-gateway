jQuery(document).ready(function ($) {
    $('#lankaqr-error-btn, #lankaqr-cancel-payment').click(function (e) {
        window.onbeforeunload = null;
        window.location.href = lankaqr_params.cancel_url;
        e.preventDefault();
    });

    $('#lankaqr-confirm-payment').click(function (e) {
        e.preventDefault();
        var tn = $('#lankaqr-transaction-number').val();
        if ('' == tn || 12 != tn.length) {
            $('#lankaqr-error-text').show();
            $('#lankaqr-transaction-number').addClass('has-error');
            return true;
        }
        $('#lankaqr-confirm-payment').prop('disabled', true);
        $('#LANKAQRJSCheckoutForm').submit();
    });

    $('#lankaqr-transaction-number').on('input', function (e) {
        this.value = this.value.replace(/\D/g, '');
        $('#lankaqr-error-text').hide();
        $('#lankaqr-transaction-number').removeClass('has-error');
    });

    setInterval(function () {
        $.ajax({
            type: 'post',
            url: lankaqr_params.ajax_check_url,
            datatype: 'JSON',
            data: {'order_id': lankaqr_params.order_id},
            success: function (data) {
                if (data.status == true) {
                    $('#lankaqr-cancel-payment').prop('disabled', true);
                    window.location.href = lankaqr_params.return_url;
                }
            }
        });
    }, 5000);//time in milliseconds

    var distance = lankaqr_params.timeout_duration;;
    var x = setInterval(function () {
        distance = distance - 1000;
        var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        var seconds = Math.floor((distance % (1000 * 60)) / 1000);

        if (minutes >= 0 && seconds >= 0) {
            $('#lankaqr-timeout').html(('0' + minutes).slice(-2) + ':' + ('0' + seconds).slice(-2));
        }
        if (distance < 0) {
            clearInterval(x);
            window.location.href = lankaqr_params.timeout_url;
        }
    }, 1000);
});