function change_group_p(url, idGroup, idUser){
    params = {
      idGroup: idGroup,
      idUser: idUser
    };

    redirect(url, params, 'POST');
}