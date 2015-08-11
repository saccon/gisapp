//GetProject (code from GetUrlParam)
var urlString = "";
var map = "";
var urlParams = {};
var guest = false;
var langsel = 'en'; //default

if (document.documentURI) {
	//all browsers except older IE
	urlString = document.documentURI;
} else {
	//older IEs do not know document.documentURI
	urlString = window.location.href;
}
urlString = urlString.replace(/\+/g, ' ');

if(GLOBAL_SERVER_OS == 'Windows NT') {
	var fullPath = Ext.urlDecode(window.location.search.substring(1)).map;
	map = fullPath.substr(fullPath.lastIndexOf('/')+1,fullPath.length);
	if (map.indexOf('.qgs')>-1)
		map = map.slice(0,map.length-4);
}
else {
	var urlArray = urlString.split('?');

    if (urlArray.length > 1) {
        urlParams = Ext.urlDecode(urlArray[1]);
        if (urlParams.public == 'on') {
            guest = true;
        }
    }

	var urlBaseArray = urlArray[0].split('/');
	map = urlBaseArray.slice(4).join('/');
}

	Ext.BLANK_IMAGE_URL = 'client/site/libs/ext/resources/images/default/s.gif';

	Ext.onReady(function() {

		if(map=='') {

			Ext.Msg.alert(TR.noProject, TR.noProjectText);

		}
		else{

            //guest access, direct call to login.php
            if(guest) {
                Ext.Ajax.request({
                    url: 'admin/login.php',
                    params: {
                        user_name: "guest",
                        user_password: "guest",
                        project: map
                    },
                    method: 'POST',
                    success: function (response) {

                        var result = Ext.util.JSON.decode(response.responseText);

                        if(result.success) {
                            if(GLOBAL_SERVER_OS == 'Windows NT') {
                                window.location.href = "index.php?map=" + fullPath + "&lang="+langsel;
                            }
                            else {
                                window.location.href = map + "?lang="+langsel;
                            }
                        }
                        else {
                            Ext.Msg.alert("Error", eval(result.message));
                        }
                    }
                });
            }
            else
            {
                Ext.QuickTips.init();

                var loginDialog = new Ext.ux.form.LoginDialog({
                    url: 'admin/login.php',
                    modal : true,
                    //forgotPasswordLink : '',
                    cancelButton: null,
                    basePath: 'admin/logindialog/img/icons',
                    encrypt: false,
                    usernameField: 'user_name',
                    passwordField: 'user_password',
                    extraParamField: 'project',
                    extraParamValue: map,
                    enableVirtualKeyboard: true,
                    onSuccess : function (form, action) {
                        if (this.fireEvent('success', this, action)) {
                            // enable buttons
                            Ext.getCmp(this._loginButtonId).enable();
                            if(Ext.getCmp(this._cancelButtonId)) {
                                Ext.getCmp(this._cancelButtonId).enable();
                            }

                            var langsel = form.items.items[2].value;
                            if(GLOBAL_SERVER_OS == 'Windows NT') {
                                window.location.href = "index.php?map=" + fullPath + "&lang="+langsel;
                            }
                            else {
                                window.location.href = map + "?lang="+langsel;
                            }
                            this.hide();
                        }
                    },
                    //text strings, leave this, look language files
                    title: TR.loginTitle,
                    message: TR.loginMessage,
                    failMessage: TR.loginFailMessage,
                    waitMessage: TR.loginWaitMessage,
                    loginButton: TR.loginButton,
                    guestButton: TR.guestButton,
                    usernameLabel: TR.loginUsernameLabel,
                    passwordLabel: TR.loginPasswordLabel,
                    languageLabel: TR.loginLanguageLabel,
                    rememberMeLabel: TR.loginRememberMeLabel,
                    forgotPasswordLabel: TR.loginForgotPasswordLabel
                });

                loginDialog.show();

            }

		}
	});

